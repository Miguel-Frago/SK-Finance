<?php

use App\Models\Budget;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Models\User;
use App\Support\JournalBook;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** A 2026 budget (₱10,000 in the bank, no cash) with one ₱20,000 item. Returns [budget, item]. */
function journalFixture(): array
{
    $budget = Budget::create([
        'year'                 => 2026,
        'type'                 => 'regular',
        'total_budget'         => 100000,
        'opening_bank_balance' => 10000,
        'opening_cash_balance' => 0,
    ]);

    $category = $budget->categories()->create(['name' => 'MOOE']);
    $item     = $category->subcategories()->create(['name' => 'Office Supplies', 'allocated_amount' => 20000]);

    return [$budget, $item];
}

function journalExpense(Subcategory $item, float $amount, string $method, string $date, array $attributes = []): Transaction
{
    return Transaction::create(array_merge([
        'date'           => $date,
        'description'    => 'Journal test expense',
        'category_id'    => $item->category_id,
        'subcategory_id' => $item->id,
        'amount'         => $amount,
        'payment_method' => $method,
    ], $attributes));
}

/**
 * Five entries, oldest first:
 *   Jan 05 receipt   +5,000 bank          -> bank 15,000 / cash 0
 *   Jan 10 withdraw   3,000 bank -> cash  -> bank 12,000 / cash 3,000
 *   Feb 01 expense    1,000 from bank     -> bank 11,000 / cash 3,000
 *   Feb 02 expense      500 from cash     -> bank 11,000 / cash 2,500
 *   Feb 03 deposit      200 cash -> bank  -> bank 11,200 / cash 2,300
 */
function journalSeed(): Budget
{
    [$budget, $item] = journalFixture();

    $budget->fundMovements()->create(['date' => '2026-01-05', 'type' => 'receipt', 'account' => 'bank', 'amount' => 5000, 'reference' => 'REL-001']);
    $budget->fundMovements()->create(['date' => '2026-01-10', 'type' => 'withdrawal', 'amount' => 3000, 'reference' => 'CHK-777']);
    journalExpense($item, 1000, 'bank', '2026-02-01', ['voucher_number' => 'V-1', 'payee' => 'National Bookstore']);
    journalExpense($item, 500, 'cash_on_hand', '2026-02-02');
    $budget->fundMovements()->create(['date' => '2026-02-03', 'type' => 'deposit', 'amount' => 200, 'reference' => 'SLIP-9']);

    return $budget;
}

beforeEach(function () {
    // Unsaved user: the project has no users migration, and the tests do not need a users row.
    $this->actingAs(new User(['name' => 'SK Treasurer', 'email' => 'treasurer@example.test']));
});

/*
|--------------------------------------------------------------------------
| The journal book (running balances)
|--------------------------------------------------------------------------
*/

it('lists expenses and fund movements in date order with running balances', function () {
    $budget  = journalSeed();
    $entries = JournalBook::entries($budget);

    expect($entries->pluck('source')->all())->toBe(['receipt', 'withdrawal', 'expense', 'expense', 'deposit'])
        ->and($entries->pluck('bank_balance')->all())->toBe([15000.0, 12000.0, 11000.0, 11000.0, 11200.0])
        ->and($entries->pluck('cash_balance')->all())->toBe([0.0, 3000.0, 3000.0, 2500.0, 2300.0])
        // the last running balance is the budget's current balance
        ->and($entries->last()['bank_balance'])->toEqual($budget->bank_balance)
        ->and($entries->last()['cash_balance'])->toEqual($budget->cash_balance);
});

it('treats a withdrawal as a transfer: bank credit and cash debit of the same amount', function () {
    $budget     = journalSeed();
    $withdrawal = JournalBook::entries($budget)->firstWhere('source', 'withdrawal');

    expect($withdrawal['bank'])->toBe(-3000.0)
        ->and($withdrawal['cash'])->toBe(3000.0);
});

it('puts money coming in before expenses on the same day', function () {
    [$budget, $item] = journalFixture();

    journalExpense($item, 100, 'cash_on_hand', '2026-03-01');
    $budget->fundMovements()->create(['date' => '2026-03-01', 'type' => 'withdrawal', 'amount' => 500]);

    expect(JournalBook::entries($budget)->pluck('source')->all())->toBe(['withdrawal', 'expense']);
});

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

it('asks for a budget when none exists yet', function () {
    $this->get(route('journal.index'))
        ->assertOk()
        ->assertSee('No budget yet');
});

it('shows the entries, the opening row and the balance cards', function () {
    $budget = journalSeed();

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('journal.index'))
        ->assertOk()
        ->assertSee('Opening balances')
        ->assertSee('REL-001')
        ->assertSee('CHK-777')
        ->assertSee('Voucher V-1')
        ->assertSee('SLIP-9')
        ->assertSee('₱11,200.00')   // current bank
        ->assertSee('₱2,300.00')    // current cash
        ->assertViewHas('totals', fn ($totals) => $totals['rows'] === 5
            && $totals['bank_in'] === 5200.0     // receipt 5,000 + deposit 200
            && $totals['bank_out'] === 4000.0    // withdrawal 3,000 + expense 1,000
            && $totals['cash_in'] === 3000.0     // withdrawal
            && $totals['cash_out'] === 700.0);   // expense 500 + deposit 200
});

it('keeps the true running balance when the list is filtered', function () {
    $budget = journalSeed();
    $this->withSession(['budget_id' => $budget->id]);

    $this->get(route('journal.index', ['source' => 'withdrawal']))
        ->assertOk()
        ->assertSee('CHK-777')
        ->assertDontSee('REL-001')
        ->assertDontSee('Opening balances')
        ->assertViewHas('entries', fn ($page) => $page->count() === 1
            && $page->first()['bank_balance'] === 12000.0
            && $page->first()['cash_balance'] === 3000.0);
});

it('filters by account, date range and search', function () {
    $budget = journalSeed();
    $this->withSession(['budget_id' => $budget->id]);

    // entries that move cash: the withdrawal, the cash expense and the deposit
    $this->get(route('journal.index', ['account' => 'cash']))
        ->assertViewHas('entries', fn ($page) => $page->count() === 3)
        ->assertViewHas('totals', fn ($totals) => $totals['cash_in'] === 3000.0 && $totals['cash_out'] === 700.0);

    // February only
    $this->get(route('journal.index', ['date_from' => '2026-02-01', 'date_to' => '2026-02-28']))
        ->assertViewHas('entries', fn ($page) => $page->count() === 3)
        ->assertDontSee('REL-001');

    // search the voucher number and the payee
    $this->get(route('journal.index', ['search' => 'V-1']))
        ->assertViewHas('entries', fn ($page) => $page->count() === 1);

    $this->get(route('journal.index', ['search' => 'bookstore']))
        ->assertViewHas('entries', fn ($page) => $page->count() === 1);
});

it('can list the newest entry first without changing the balances', function () {
    $budget = journalSeed();

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('journal.index', ['order' => 'desc']))
        ->assertOk()
        ->assertDontSee('Opening balances')
        ->assertViewHas('entries', fn ($page) => $page->first()['source'] === 'deposit'
            && $page->first()['bank_balance'] === 11200.0);
});

it('pages the journal and continues the running balance on the next page', function () {
    [$budget] = journalFixture();

    foreach (range(1, 25) as $day) {
        $budget->fundMovements()->create(['date' => sprintf('2026-03-%02d', $day), 'type' => 'receipt', 'account' => 'bank', 'amount' => 100]);
    }

    $this->withSession(['budget_id' => $budget->id]);

    $this->get(route('journal.index'))
        ->assertViewHas('entries', fn ($page) => $page->count() === 20 && $page->total() === 25);

    // opening 10,000 + 21 receipts of 100 = 12,100 on the first row of page 2
    $this->get(route('journal.index', ['page' => 2]))
        ->assertViewHas('entries', fn ($page) => $page->count() === 5
            && $page->currentPage() === 2
            && $page->first()['bank_balance'] === 12100.0
            && $page->last()['bank_balance'] === 12500.0);
});

it('rejects an end date before the start date', function () {
    $budget = journalSeed();

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('journal.index', ['date_from' => '2026-03-01', 'date_to' => '2026-02-01']))
        ->assertSessionHasErrors('date_to');
});
