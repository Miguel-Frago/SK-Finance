<?php

use App\Models\ActivityLog;
use App\Models\Barangay;
use App\Models\Budget;
use App\Models\Category;
use App\Models\ReportSetting;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A saved account of a barangay (found by its slug, e.g. 'suyo'). */
function portalUser(string $slug, string $role = 'treasurer'): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role, 'barangay_id' => Barangay::where('slug', $slug)->value('id')])->save();

    return $user;
}

/** A budget with one category, one item and one expense, made by whoever is signed in. */
function portalSpending(): Transaction
{
    $budget = Budget::create([
        'year' => 2026, 'type' => 'regular', 'total_budget' => 100000,
        'opening_bank_balance' => 0, 'opening_cash_balance' => 0,
    ]);
    $category = Category::create(['budget_id' => $budget->id, 'name' => 'Sports']);
    $item     = Subcategory::create(['category_id' => $category->id, 'name' => 'Ball', 'allocated_amount' => 5000]);

    return Transaction::create([
        'date' => '2026-03-01', 'description' => 'Snacks for the league', 'category_id' => $category->id,
        'subcategory_id' => $item->id, 'amount' => 100, 'payment_method' => 'cash_on_hand',
    ]);
}

it('has the 40 barangays of Luna with every district left empty', function () {
    expect(Barangay::count())->toBe(40)
        ->and(Barangay::whereNotNull('district')->count())->toBe(0)
        ->and(Barangay::where('name', 'Cantoria No. 4')->exists())->toBeTrue()
        ->and(Barangay::where('name', 'Busel-Busel')->exists())->toBeTrue()
        ->and(Barangay::where('chairman_can_edit', true)->count())->toBe(0);
});

it('stamps new records with the barangay of the person who made them', function () {
    $suyo = portalUser('suyo');
    $this->actingAs($suyo);

    $tx = portalSpending();

    expect(Budget::withoutGlobalScopes()->first()->barangay_id)->toEqual($suyo->barangay_id)
        ->and(Transaction::withoutGlobalScopes()->find($tx->id)->barangay_id)->toEqual($suyo->barangay_id);
});

it('keeps one barangay from seeing the records of another', function () {
    $this->actingAs(portalUser('cantoria-no-4'));
    portalSpending();

    $this->actingAs(portalUser('suyo'));

    expect(Budget::count())->toBe(0)
        ->and(Category::count())->toBe(0)
        ->and(Subcategory::count())->toBe(0)
        ->and(Transaction::count())->toBe(0);

    $this->get(route('transactions.index'))->assertOk()->assertDontSee('Snacks for the league');
});

it('gives a 404 for a link into another barangay, and changes nothing there', function () {
    $this->actingAs(portalUser('cantoria-no-4'));
    $tx = portalSpending();

    $this->actingAs(portalUser('suyo'));

    $this->get(route('transactions.edit', $tx))->assertNotFound();
    $this->post(route('transactions.void', $tx), ['void_reason' => 'Trying another barangay'])->assertNotFound();

    expect(Transaction::withoutGlobalScopes()->whereKey($tx->id)->exists())->toBeTrue();
});

it('lets every barangay have its own regular budget for the same year', function () {
    $this->actingAs(portalUser('cantoria-no-4'));
    Budget::create(['year' => 2026, 'type' => 'regular', 'total_budget' => 1, 'opening_bank_balance' => 0, 'opening_cash_balance' => 0]);

    $this->actingAs(portalUser('suyo'));
    Budget::create(['year' => 2026, 'type' => 'regular', 'total_budget' => 2, 'opening_bank_balance' => 0, 'opening_cash_balance' => 0]);

    expect(Budget::withoutGlobalScopes()->where('year', 2026)->count())->toBe(2);
});

it('keeps the names and signatures of the report separate for each barangay', function () {
    $this->actingAs(portalUser('cantoria-no-4'));
    ReportSetting::write('signatories', ['reportNo' => 'CANTORIA-1']);

    $this->actingAs(portalUser('suyo'));
    expect(ReportSetting::read('signatories'))->toBe([]);
    ReportSetting::write('signatories', ['reportNo' => 'SUYO-1']);

    $this->actingAs(portalUser('cantoria-no-4'));
    expect(ReportSetting::read('signatories'))->toBe(['reportNo' => 'CANTORIA-1']);
});

/* ------------------------------------------------------------
 | The treasurer's switch: may the chairman edit finance records?
 * ------------------------------------------------------------ */

it('keeps the chairman view-only until the treasurer switches editing on, and off again', function () {
    $treasurer = portalUser('cantoria-no-4', 'treasurer');
    $chairman  = portalUser('cantoria-no-4', 'chairman');

    $this->actingAs($treasurer);
    portalSpending();

    $this->actingAs($chairman)->get('/transactions/create')->assertForbidden();

    $this->actingAs($treasurer)
        ->put(route('barangay.settings'), ['chairman_can_edit' => '1'])
        ->assertRedirect(route('profile.edit'));

    expect(Barangay::find($treasurer->barangay_id)->chairman_can_edit)->toBeTrue();

    $this->actingAs($chairman->fresh())->get('/transactions/create')->assertOk();

    $this->actingAs($treasurer)->put(route('barangay.settings'), [])->assertRedirect(route('profile.edit'));

    expect(Barangay::find($treasurer->barangay_id)->chairman_can_edit)->toBeFalse();

    $this->actingAs($chairman->fresh())->get('/transactions/create')->assertForbidden();
});

it('lets only the treasurer use the switch', function () {
    foreach (['chairman', 'secretary', 'kagawad'] as $role) {
        $this->actingAs(portalUser('cantoria-no-4', $role))
            ->put(route('barangay.settings'), ['chairman_can_edit' => '1'])
            ->assertForbidden();
    }

    expect(Barangay::where('chairman_can_edit', true)->count())->toBe(0);
});

it('does not let one barangay switch editing on for another', function () {
    $this->actingAs(portalUser('suyo', 'treasurer'))
        ->put(route('barangay.settings'), ['chairman_can_edit' => '1']);

    $this->actingAs(portalUser('cantoria-no-4', 'chairman'))->get('/transactions/create')->assertForbidden();
});

it('shows the switch to the treasurer on the profile page', function () {
    $this->actingAs(portalUser('cantoria-no-4', 'treasurer'))
        ->get(route('profile.edit'))->assertOk()
        ->assertSee('Let the SK Chairman add and edit');

    $this->actingAs(portalUser('cantoria-no-4', 'chairman'))
        ->get(route('profile.edit'))->assertOk()
        ->assertDontSee('Let the SK Chairman add and edit')
        ->assertSee('Only the SK Treasurer can change this');
});

/* ------------------------------------------------------------
 | Switched-off accounts (term ended, resigned)
 * ------------------------------------------------------------ */

it('does not let a switched-off account sign in, and says why', function () {
    $user = portalUser('cantoria-no-4');
    $user->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'This account has been switched off. Please contact the SK Federation.']);

    $this->assertGuest();
});

it('signs out an account that is switched off while it is signed in', function () {
    $user = portalUser('cantoria-no-4');

    $this->actingAs($user)->get('/budget')->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get('/budget')->assertRedirect(route('login'));
});

it('still lets an active account sign in', function () {
    $user = portalUser('cantoria-no-4');

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

/* ------------------------------------------------------------
 | Activity log
 * ------------------------------------------------------------ */

it('logs who created a record, in which barangay', function () {
    $suyo = portalUser('suyo');
    $this->actingAs($suyo);

    $budget = Budget::create(['year' => 2026, 'type' => 'regular', 'total_budget' => 1, 'opening_bank_balance' => 0, 'opening_cash_balance' => 0]);

    $log = ActivityLog::where('subject_type', 'Budget')->where('subject_id', $budget->id)->where('action', 'created')->first();

    expect($log)->not->toBeNull()
        ->and($log->barangay_id)->toEqual($suyo->barangay_id)
        ->and($log->user_id)->toEqual($suyo->id)
        ->and($log->user_name)->toBe($suyo->name);
});

it('logs a change with the old and the new value', function () {
    $this->actingAs(portalUser('suyo'));

    $budget = Budget::create(['year' => 2026, 'type' => 'regular', 'total_budget' => 1000, 'opening_bank_balance' => 0, 'opening_cash_balance' => 0]);
    $budget->update(['total_budget' => 2500]);

    $log = ActivityLog::where('subject_type', 'Budget')->where('action', 'updated')->first();

    expect($log)->not->toBeNull()
        ->and($log->details)->toHaveKey('total_budget');
});

it('logs a deletion', function () {
    $this->actingAs(portalUser('suyo'));

    $tx = portalSpending();
    $tx->delete();

    expect(ActivityLog::where('subject_type', 'Transaction')->where('subject_id', $tx->id)->where('action', 'deleted')->exists())->toBeTrue();
});

it('logs signing in and the treasurer\'s switch', function () {
    $user = portalUser('cantoria-no-4');

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect(ActivityLog::where('action', 'login')->where('user_id', $user->id)->exists())->toBeTrue();

    $this->put(route('barangay.settings'), ['chairman_can_edit' => '1']);

    expect(ActivityLog::where('subject_type', 'Barangay')->where('action', 'updated')->exists())->toBeTrue();
});
