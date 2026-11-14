<?php

use App\Models\Budget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/** A saved account with the given role. */
function printAccount(string $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user;
}

// (The Viewer role was removed. The Secretary and Kagawads are the view-only roles now.)

it('does not allow a kagawad or the secretary to print, but allows the treasurer and the chairman', function () {
    expect(Gate::forUser(printAccount('kagawad'))->allows('print'))->toBeFalse();
    expect(Gate::forUser(printAccount('secretary'))->allows('print'))->toBeFalse();
    expect(Gate::forUser(printAccount('chairman'))->allows('print'))->toBeTrue();
    expect(Gate::forUser(printAccount('treasurer'))->allows('print'))->toBeTrue();
});

it('marks the pages of a kagawad so the browser prints them blank', function () {
    $this->actingAs(printAccount('kagawad'))->get('/budget')
        ->assertOk()
        ->assertSee('class="no-printing"', false);
});

it('hides the Print button on the report page from a kagawad but not from the chairman', function () {
    Budget::create([
        'year' => 2026, 'type' => 'regular', 'total_budget' => 100000,
        'opening_bank_balance' => 10000, 'opening_cash_balance' => 5000,
    ]);

    $this->actingAs(printAccount('kagawad'))->get('/reports')
        ->assertOk()
        ->assertSee('Report Generation')
        ->assertDontSee('Print / Save as PDF</button>', false);

    $this->actingAs(printAccount('chairman'))->get('/reports')
        ->assertOk()
        ->assertSee('Print / Save as PDF</button>', false);
});
