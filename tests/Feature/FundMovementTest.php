<?php

use App\Models\Budget;
use App\Models\FundMovement;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** A 2026 budget (₱10,000 in the bank, no cash) with one ₱20,000 item. Returns [budget, item]. */
function fundFixture(array $budget = []): array
{
    $budget = Budget::create(array_merge([
        'year'                 => 2026,
        'type'                 => 'regular',
        'total_budget'         => 100000,
        'opening_bank_balance' => 10000,
        'opening_cash_balance' => 0,
    ], $budget));

    $category = $budget->categories()->create(['name' => 'MOOE']);
    $item     = $category->subcategories()->create(['name' => 'Office Supplies', 'allocated_amount' => 20000]);

    return [$budget, $item];
}

/** Form data for POST/PUT fund-movements (a bank receipt unless overridden). */
function fundPayload(array $overrides = []): array
{
    return array_merge([
        'date'      => '2026-03-15',
        'type'      => 'receipt',
        'account'   => 'bank',
        'amount'    => 1000,
        'reference' => 'REF-001',
        'remarks'   => 'Test movement',
    ], $overrides);
}

/** Insert a movement directly, bypassing the form rules. */
function makeMovement(Budget $budget, string $type, float $amount, ?string $account = null, array $attributes = []): FundMovement
{
    return $budget->fundMovements()->create(array_merge([
        'date'    => '2026-03-01',
        'type'    => $type,
        'account' => $type === 'receipt' ? ($account ?? 'bank') : null,
        'amount'  => $amount,
    ], $attributes));
}

/** Insert an expense directly (the money rules of the expense form are not under test here). */
function fundExpense(Subcategory $item, float $amount, string $method): Transaction
{
    return Transaction::create([
        'date'           => '2026-03-10',
        'description'    => 'Test expense',
        'category_id'    => $item->category_id,
        'subcategory_id' => $item->id,
        'amount'         => $amount,
        'payment_method' => $method,
    ]);
}

beforeEach(function () {
    // Sign in as an unsaved user: the project has no users migration, and the tests do not need a users row.
    $this->actingAs(new User(['name' => 'SK Treasurer', 'email' => 'treasurer@example.test']));
});

/*
|--------------------------------------------------------------------------
| Recording movements
|--------------------------------------------------------------------------
*/

it('records a receipt into the bank', function () {
    [$budget] = fundFixture();

    $this->post(route('fund-movements.store'), fundPayload(['type' => 'receipt', 'account' => 'bank', 'amount' => 5000]))
        ->assertRedirect(route('fund-movements.index'))
        ->assertSessionHasNoErrors();

    $movement = FundMovement::firstOrFail();

    expect($movement->budget_id)->toEqual($budget->id)
        ->and($movement->type)->toBe('receipt')
        ->and($movement->account)->toBe('bank')
        ->and((float) $movement->amount)->toEqual(5000.0)
        ->and($movement->reference)->toBe('REF-001')
        ->and($budget->fresh()->bank_balance)->toEqual(15000.0)
        ->and($budget->fresh()->cash_balance)->toEqual(0.0);
});

it('records a receipt into cash on hand', function () {
    [$budget] = fundFixture();

    $this->post(route('fund-movements.store'), fundPayload(['account' => 'cash_on_hand', 'amount' => 3000]))
        ->assertSessionHasNoErrors();

    expect($budget->fresh()->bank_balance)->toEqual(10000.0)
        ->and($budget->fresh()->cash_balance)->toEqual(3000.0);
});

it('requires an account for a receipt', function () {
    fundFixture();

    $this->post(route('fund-movements.store'), fundPayload(['account' => null]))
        ->assertSessionHasErrors('account');

    expect(FundMovement::count())->toBe(0);
});

it('moves money from the bank to cash on a withdrawal', function () {
    [$budget] = fundFixture();

    $this->post(route('fund-movements.store'), fundPayload(['type' => 'withdrawal', 'account' => null, 'amount' => 4000]))
        ->assertRedirect(route('fund-movements.index'))
        ->assertSessionHasNoErrors();

    $movement = FundMovement::firstOrFail();

    expect($movement->account)->toBeNull()
        ->and($budget->fresh()->bank_balance)->toEqual(6000.0)
        ->and($budget->fresh()->cash_balance)->toEqual(4000.0)
        ->and($budget->fresh()->total_funds_on_hand)->toEqual(10000.0) // a transfer, not an expense
        ->and($budget->fresh()->total_spent)->toEqual(0.0)
        ->and($budget->fresh()->remaining_budget)->toEqual(100000.0); // nothing spent yet
});

it('moves money from cash to the bank on a deposit', function () {
    [$budget] = fundFixture(['opening_cash_balance' => 3000]);

    $this->post(route('fund-movements.store'), fundPayload(['type' => 'deposit', 'account' => null, 'amount' => 2000]))
        ->assertSessionHasNoErrors();

    expect($budget->fresh()->bank_balance)->toEqual(12000.0)
        ->and($budget->fresh()->cash_balance)->toEqual(1000.0)
        ->and($budget->fresh()->total_funds_on_hand)->toEqual(13000.0);
});

it('lets a withdrawal take the whole bank balance but not a centavo more', function () {
    [$budget] = fundFixture();

    $this->post(route('fund-movements.store'), fundPayload(['type' => 'withdrawal', 'account' => null, 'amount' => 10000]))
        ->assertSessionHasNoErrors();

    expect($budget->fresh()->bank_balance)->toEqual(0.0)
        ->and($budget->fresh()->cash_balance)->toEqual(10000.0);

    $this->post(route('fund-movements.store'), fundPayload(['type' => 'withdrawal', 'account' => null, 'amount' => 0.01]))
        ->assertSessionHasErrors('amount');

    expect(FundMovement::count())->toBe(1);
});

it('keeps the unspent budget unchanged after a withdrawal until the cash is spent', function () {
    [$budget, $item] = fundFixture();

    $this->post(route('fund-movements.store'), fundPayload(['type' => 'withdrawal', 'account' => null, 'amount' => 4000]))
        ->assertSessionHasNoErrors();

    // cash withdrawn but not yet spent
    expect($budget->fresh()->bank_balance)->toEqual(6000.0)
        ->and($budget->fresh()->cash_balance)->toEqual(4000.0)
        ->and($budget->fresh()->remaining_budget)->toEqual(100000.0);

    fundExpense($item, 1000, 'cash_on_hand');

    // only the expense reduces cash, total funds and the unspent budget
    expect($budget->fresh()->bank_balance)->toEqual(6000.0)
        ->and($budget->fresh()->cash_balance)->toEqual(3000.0)
        ->and($budget->fresh()->total_funds_on_hand)->toEqual(9000.0)
        ->and($budget->fresh()->remaining_budget)->toEqual(99000.0);
});

/*
|--------------------------------------------------------------------------
| Validation
|--------------------------------------------------------------------------
*/

it('rejects a withdrawal bigger than the bank balance', function () {
    [$budget] = fundFixture();

    $this->post(route('fund-movements.store'), fundPayload(['type' => 'withdrawal', 'account' => null, 'amount' => 10000.01]))
        ->assertSessionHasErrors('amount');

    expect(FundMovement::count())->toBe(0)
        ->and($budget->fresh()->bank_balance)->toEqual(10000.0);
});

it('counts bank expenses when checking a withdrawal', function () {
    [$budget, $item] = fundFixture();
    fundExpense($item, 7000, 'bank'); // bank is now 3,000

    $this->post(route('fund-movements.store'), fundPayload(['type' => 'withdrawal', 'account' => null, 'amount' => 3500]))
        ->assertSessionHasErrors('amount');

    expect(FundMovement::count())->toBe(0);
});

it('rejects a deposit bigger than the cash on hand', function () {
    [$budget] = fundFixture(['opening_cash_balance' => 500]);

    $this->post(route('fund-movements.store'), fundPayload(['type' => 'deposit', 'account' => null, 'amount' => 500.01]))
        ->assertSessionHasErrors('amount');

    expect(FundMovement::count())->toBe(0)
        ->and($budget->fresh()->cash_balance)->toEqual(500.0);
});

it('rejects a deposit when there is no cash on hand at all', function () {
    fundFixture(); // all money is still in the bank

    $this->post(route('fund-movements.store'), fundPayload(['type' => 'deposit', 'account' => null, 'amount' => 100]))
        ->assertSessionHasErrors('amount');

    expect(FundMovement::count())->toBe(0);
});

it('does not accept an account for withdrawals and deposits', function () {
    fundFixture(['opening_cash_balance' => 1000]);

    $this->post(route('fund-movements.store'), fundPayload(['type' => 'withdrawal', 'account' => 'bank', 'amount' => 100]))
        ->assertSessionHasErrors('account');

    $this->post(route('fund-movements.store'), fundPayload(['type' => 'deposit', 'account' => 'cash_on_hand', 'amount' => 100]))
        ->assertSessionHasErrors('account');

    expect(FundMovement::count())->toBe(0);
});

it('rejects a date outside the budget year', function () {
    fundFixture();

    $this->post(route('fund-movements.store'), fundPayload(['date' => '2027-01-02']))
        ->assertSessionHasErrors('date');

    $this->post(route('fund-movements.store'), fundPayload(['date' => '2025-12-31']))
        ->assertSessionHasErrors('date');

    expect(FundMovement::count())->toBe(0);
});

it('rejects an amount of zero, a negative amount or more than 2 decimals', function () {
    fundFixture();

    foreach ([0, -50, 100.555] as $amount) {
        $this->post(route('fund-movements.store'), fundPayload(['amount' => $amount]))
            ->assertSessionHasErrors('amount');
    }

    expect(FundMovement::count())->toBe(0);
});

it('rejects an unknown type', function () {
    fundFixture();

    $this->post(route('fund-movements.store'), fundPayload(['type' => 'expense']))
        ->assertSessionHasErrors('type');

    expect(FundMovement::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Voiding (never leaves bank or cash negative)
|--------------------------------------------------------------------------
*/

it('voids a movement when the balances stay at zero or above', function () {
    [$budget] = fundFixture();
    $withdrawal = makeMovement($budget, 'withdrawal', 4000);

    $this->post(route('fund-movements.void', $withdrawal), ['void_reason' => 'Entered twice by mistake'])
        ->assertSessionHasNoErrors();

    // it counts nowhere, but it is still in the books with its reason
    expect(FundMovement::count())->toBe(0)
        ->and(FundMovement::withoutGlobalScope('notVoided')->count())->toBe(1)
        ->and(FundMovement::withoutGlobalScope('notVoided')->first()->void_reason)->toBe('Entered twice by mistake')
        ->and($budget->fresh()->bank_balance)->toEqual(10000.0)
        ->and($budget->fresh()->cash_balance)->toEqual(0.0);
});

it('blocks voiding a withdrawal when cash expenses depend on it', function () {
    [$budget, $item] = fundFixture();
    $withdrawal = makeMovement($budget, 'withdrawal', 5000);
    fundExpense($item, 4000, 'cash_on_hand'); // cash is now 1,000

    $this->post(route('fund-movements.void', $withdrawal), ['void_reason' => 'Entered twice by mistake'])
        ->assertSessionHasErrors('fund_movement');

    expect(FundMovement::count())->toBe(1)
        ->and($budget->fresh()->cash_balance)->toEqual(1000.0);
});

it('blocks voiding a receipt when bank expenses depend on it', function () {
    [$budget, $item] = fundFixture(['opening_bank_balance' => 0]);
    $receipt = makeMovement($budget, 'receipt', 5000, 'bank');
    fundExpense($item, 4000, 'bank');

    $this->post(route('fund-movements.void', $receipt), ['void_reason' => 'Entered twice by mistake'])
        ->assertSessionHasErrors('fund_movement');

    expect(FundMovement::count())->toBe(1)
        ->and($budget->fresh()->bank_balance)->toEqual(1000.0);
});

it('blocks voiding a deposit when later bank expenses used that money', function () {
    [$budget, $item] = fundFixture(['opening_bank_balance' => 0, 'opening_cash_balance' => 3000]);
    $deposit = makeMovement($budget, 'deposit', 3000);
    fundExpense($item, 2500, 'bank'); // bank is now 500

    $this->post(route('fund-movements.void', $deposit), ['void_reason' => 'Entered twice by mistake'])
        ->assertSessionHasErrors('fund_movement');

    expect(FundMovement::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Editing (the old effect is removed first, then the new one applied)
|--------------------------------------------------------------------------
*/

it('updates a movement and the balances', function () {
    [$budget] = fundFixture();
    $receipt = makeMovement($budget, 'receipt', 5000, 'bank');

    $this->put(route('fund-movements.update', $receipt), fundPayload(['amount' => 6000, 'reference' => 'REF-002']))
        ->assertRedirect(route('fund-movements.index'))
        ->assertSessionHasNoErrors();

    expect((float) $receipt->fresh()->amount)->toEqual(6000.0)
        ->and($receipt->fresh()->reference)->toBe('REF-002')
        ->and($budget->fresh()->bank_balance)->toEqual(16000.0);
});

it('does not count the edited movement against itself', function () {
    [$budget] = fundFixture();
    $withdrawal = makeMovement($budget, 'withdrawal', 4000); // bank is now 6,000

    // 10,000 is more than the 6,000 left, but fine once this withdrawal's own 4,000 is given back
    $this->put(route('fund-movements.update', $withdrawal), fundPayload(['type' => 'withdrawal', 'account' => null, 'amount' => 10000]))
        ->assertSessionHasNoErrors();

    expect($budget->fresh()->bank_balance)->toEqual(0.0)
        ->and($budget->fresh()->cash_balance)->toEqual(10000.0);

    $this->put(route('fund-movements.update', $withdrawal), fundPayload(['type' => 'withdrawal', 'account' => null, 'amount' => 10000.01]))
        ->assertSessionHasErrors('amount');
});

it('blocks lowering a withdrawal when cash expenses already used that money', function () {
    [$budget, $item] = fundFixture();
    $withdrawal = makeMovement($budget, 'withdrawal', 5000);
    fundExpense($item, 4000, 'cash_on_hand');

    $this->put(route('fund-movements.update', $withdrawal), fundPayload(['type' => 'withdrawal', 'account' => null, 'amount' => 3000]))
        ->assertSessionHasErrors('amount');

    expect((float) $withdrawal->fresh()->amount)->toEqual(5000.0);

    // 4,000 still covers the 4,000 of cash expenses, so this one is allowed
    $this->put(route('fund-movements.update', $withdrawal), fundPayload(['type' => 'withdrawal', 'account' => null, 'amount' => 4000]))
        ->assertSessionHasNoErrors();

    expect($budget->fresh()->cash_balance)->toEqual(0.0);
});

it('blocks moving a receipt to the other account when bank expenses depend on it', function () {
    [$budget, $item] = fundFixture(['opening_bank_balance' => 0]);
    $receipt = makeMovement($budget, 'receipt', 5000, 'bank');
    fundExpense($item, 4000, 'bank');

    $this->put(route('fund-movements.update', $receipt), fundPayload(['account' => 'cash_on_hand', 'amount' => 5000]))
        ->assertSessionHasErrors('amount');

    expect($receipt->fresh()->account)->toBe('bank')
        ->and($budget->fresh()->bank_balance)->toEqual(1000.0);
});

it('blocks changing a withdrawal into a deposit when that would overdraw cash', function () {
    [$budget, $item] = fundFixture();
    $withdrawal = makeMovement($budget, 'withdrawal', 5000);
    fundExpense($item, 2000, 'cash_on_hand'); // cash is 3,000

    $this->put(route('fund-movements.update', $withdrawal), fundPayload(['type' => 'deposit', 'account' => null, 'amount' => 5000]))
        ->assertSessionHasErrors('amount');

    expect($withdrawal->fresh()->type)->toBe('withdrawal');
});

it('rejects editing a movement to a date outside the budget year', function () {
    [$budget] = fundFixture();
    $receipt = makeMovement($budget, 'receipt', 5000, 'bank');

    $this->put(route('fund-movements.update', $receipt), fundPayload(['date' => '2027-06-01']))
        ->assertSessionHasErrors('date');

    expect($receipt->fresh()->date->format('Y-m-d'))->toBe('2026-03-01');
});

it('keeps an edited movement in its own budget', function () {
    [$budget] = fundFixture();
    $receipt = makeMovement($budget, 'receipt', 5000, 'bank');

    // A newer budget becomes the default one, but the edit must still be checked against the old budget's year
    Budget::create(['year' => 2027, 'type' => 'regular', 'total_budget' => 1000, 'opening_bank_balance' => 0, 'opening_cash_balance' => 0]);

    $this->put(route('fund-movements.update', $receipt), fundPayload(['date' => '2027-02-01']))
        ->assertSessionHasErrors('date');

    $this->put(route('fund-movements.update', $receipt), fundPayload(['date' => '2026-04-01']))
        ->assertSessionHasNoErrors();

    expect($receipt->fresh()->budget_id)->toEqual($budget->id);
});

/*
|--------------------------------------------------------------------------
| Pages
|--------------------------------------------------------------------------
*/

it('shows the create and edit forms with the current balances', function () {
    [$budget] = fundFixture();
    $receipt = makeMovement($budget, 'receipt', 5000, 'bank');

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('fund-movements.create'))
        ->assertOk()
        ->assertSee('Record fund movement')
        ->assertSee('₱15,000.00');

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('fund-movements.edit', $receipt))
        ->assertOk()
        ->assertSee('Edit fund movement');
});

it('shows the balance cards on the list page', function () {
    [$budget] = fundFixture();
    makeMovement($budget, 'receipt', 5000, 'bank', ['reference' => 'REL-001']);
    makeMovement($budget, 'withdrawal', 2000, null, ['reference' => 'WD-001']);
    makeMovement($budget, 'deposit', 500, null, ['reference' => 'DEP-001']);

    // bank: 10,000 + 5,000 - 2,000 + 500 = 13,500 / cash: 2,000 - 500 = 1,500
    $this->withSession(['budget_id' => $budget->id])
        ->get(route('fund-movements.index'))
        ->assertOk()
        ->assertSee('₱13,500.00')
        ->assertSee('₱1,500.00')
        ->assertSee('₱15,000.00')
        ->assertSee('Budget not yet spent')
        ->assertSee('₱100,000.00')
        ->assertSee('REL-001')
        ->assertSee('WD-001')
        ->assertSee('DEP-001');
});

it('filters the list and totals only the matching rows', function () {
    [$budget] = fundFixture();
    makeMovement($budget, 'receipt', 5000, 'bank', ['reference' => 'REL-001', 'date' => '2026-01-10']);
    makeMovement($budget, 'withdrawal', 2000, null, ['reference' => 'WD-001', 'date' => '2026-02-10']);
    makeMovement($budget, 'deposit', 500, null, ['reference' => 'DEP-001', 'date' => '2026-03-10', 'remarks' => 'Unspent cash']);

    $this->withSession(['budget_id' => $budget->id]);

    // by type
    $this->get(route('fund-movements.index', ['type' => 'withdrawal']))
        ->assertOk()
        ->assertSee('WD-001')
        ->assertDontSee('REL-001')
        ->assertDontSee('DEP-001')
        ->assertViewHas('totals', fn ($totals) => (float) $totals->withdrawn === 2000.0
            && (float) $totals->received === 0.0
            && (float) $totals->deposited === 0.0
            && (int) $totals->rows_count === 1);

    // by date range
    $this->get(route('fund-movements.index', ['date_from' => '2026-02-01', 'date_to' => '2026-03-31']))
        ->assertOk()
        ->assertDontSee('REL-001')
        ->assertSee('WD-001')
        ->assertSee('DEP-001')
        ->assertViewHas('totals', fn ($totals) => (float) $totals->received === 0.0
            && (float) $totals->withdrawn === 2000.0
            && (float) $totals->deposited === 500.0);

    // by search (reference or remarks)
    $this->get(route('fund-movements.index', ['search' => 'Unspent']))
        ->assertOk()
        ->assertSee('DEP-001')
        ->assertDontSee('WD-001');

    // by the account a receipt went into
    makeMovement($budget, 'receipt', 800, 'cash_on_hand', ['reference' => 'CASH-REL']);

    $this->get(route('fund-movements.index', ['account' => 'cash_on_hand']))
        ->assertOk()
        ->assertSee('CASH-REL')
        ->assertDontSee('REL-001');
});
