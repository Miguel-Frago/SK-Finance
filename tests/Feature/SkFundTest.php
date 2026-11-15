<?php

use App\Models\Barangay;
use App\Models\SkFundAllocation;
use App\Models\SkFundTransfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function skFundUser(string $role = 'treasurer', string $slug = 'cantoria-no-4'): User
{
    $user = User::factory()->create();
    $user->forceFill([
        'role' => $role,
        'barangay_id' => Barangay::where('slug', $slug)->value('id'),
    ])->save();

    return $user;
}

it('lets the Treasurer save an annual allocation for their own barangay', function () {
    $user = skFundUser();

    $this->actingAs($user)->post(route('sk-fund.allocation'), [
        'year' => 2026,
        'allocation_amount' => '1110000.00',
        'monthly_allocation' => '92500.00',
        'remarks' => 'Approved annual allocation',
    ])->assertRedirect(route('sk-fund.index', ['year' => 2026]));

    expect(SkFundAllocation::where('year', 2026)->first())
        ->not->toBeNull()
        ->and((float) SkFundAllocation::where('year', 2026)->first()->allocation_amount)->toBe(1110000.0);
});

it('tracks 10% SK Fund and SK Surplus transfers separately', function () {
    $user = skFundUser();
    $this->actingAs($user)->post(route('sk-fund.allocation'), [
        'year' => 2026, 'allocation_amount' => 100000, 'monthly_allocation' => 8000,
    ]);

    $allocation = SkFundAllocation::where('year', 2026)->first();

    $this->post(route('sk-fund.transfers.store'), [
        'sk_fund_allocation_id' => $allocation->id,
        'transfer_date' => '2026-06-01',
        'source' => 'sk_fund',
        'amount' => 50000,
    ])->assertRedirect();

    $this->post(route('sk-fund.transfers.store'), [
        'sk_fund_allocation_id' => $allocation->id,
        'transfer_date' => '2026-06-02',
        'source' => 'sk_surplus',
        'amount' => 18000,
    ])->assertRedirect();

    expect((float) $allocation->fresh()->sk_fund_transferred)->toBe(50000.0)
        ->and((float) $allocation->fresh()->surplus_transferred)->toBe(18000.0)
        ->and((float) $allocation->fresh()->remaining_sk_fund)->toBe(50000.0);
});

it('prevents transfers above the remaining 10% SK Fund allocation', function () {
    $user = skFundUser();
    $this->actingAs($user)->post(route('sk-fund.allocation'), [
        'year' => 2026, 'allocation_amount' => 100000,
    ]);
    $allocation = SkFundAllocation::where('year', 2026)->first();

    $this->post(route('sk-fund.transfers.store'), [
        'sk_fund_allocation_id' => $allocation->id,
        'transfer_date' => '2026-06-01',
        'source' => 'sk_fund',
        'amount' => 100001,
    ])->assertSessionHasErrors('amount');

    expect(SkFundTransfer::count())->toBe(0);
});

it('does not let viewers record or alter SK Fund data', function () {
    $viewer = skFundUser('kagawad');

    $this->actingAs($viewer)->post(route('sk-fund.allocation'), [
        'year' => 2026, 'allocation_amount' => 100000,
    ])->assertForbidden();
});

it('keeps SK Fund allocation data isolated by barangay', function () {
    $first = skFundUser('treasurer', 'cantoria-no-4');
    $this->actingAs($first)->post(route('sk-fund.allocation'), [
        'year' => 2026, 'allocation_amount' => 100000,
    ]);
    $allocation = SkFundAllocation::where('year', 2026)->first();

    $second = skFundUser('treasurer', 'suyo');
    $this->actingAs($second)->get(route('sk-fund.index', ['year' => 2026]))
        ->assertOk()->assertSee('No allocation recorded');
    $this->post(route('sk-fund.transfers.store'), [
        'sk_fund_allocation_id' => $allocation->id,
        'transfer_date' => '2026-06-01',
        'source' => 'sk_fund',
        'amount' => 1000,
    ])->assertSessionHasErrors('sk_fund_allocation_id');
});
