<?php

use App\Models\Budget;
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

/** A 2026 budget with one category (MOOE) and one ₱10,000 item. Returns [budget, item, category]. */
function ledgerFixture(): array
{
    $budget = Budget::create([
        'year'                 => 2026,
        'type'                 => 'regular',
        'total_budget'         => 100000,
        'opening_bank_balance' => 50000,
        'opening_cash_balance' => 10000,
    ]);

    $category = $budget->categories()->create(['name' => 'MOOE']);
    $item     = $category->subcategories()->create(['name' => 'Office Supplies', 'allocated_amount' => 10000]);

    return [$budget, $item, $category];
}

function ledgerSpend(Subcategory $item, float $amount, string $date, string $method = 'bank', array $attributes = []): Transaction
{
    return Transaction::create(array_merge([
        'date'           => $date,
        'description'    => 'Ledger test expense',
        'category_id'    => $item->category_id,
        'subcategory_id' => $item->id,
        'amount'         => $amount,
        'payment_method' => $method,
    ], $attributes));
}

/**
 * The ₱10,000 item with three expenses, oldest first:
 *   Feb 01  1,000 bank  (V-1, National Bookstore)  -> balance 9,000
 *   Feb 05  2,500 cash                              -> balance 6,500
 *   Mar 01  4,000 bank  (check CHK-5)               -> balance 2,500
 */
function ledgerSeed(): array
{
    [$budget, $item, $category] = ledgerFixture();

    ledgerSpend($item, 1000, '2026-02-01', 'bank', ['voucher_number' => 'V-1', 'payee' => 'National Bookstore', 'description' => 'Bond paper']);
    ledgerSpend($item, 2500, '2026-02-05', 'cash_on_hand', ['description' => 'Printer ink']);
    ledgerSpend($item, 4000, '2026-03-01', 'bank', ['check_number' => 'CHK-5', 'description' => 'Folders']);

    return [$budget, $item, $category];
}

beforeEach(function () {
    // Unsaved user: the project has no users migration, and the tests do not need a users row.
    $this->actingAs(new User(['name' => 'SK Treasurer', 'email' => 'treasurer@example.test']));
});

/*
|--------------------------------------------------------------------------
| Page basics
|--------------------------------------------------------------------------
*/

it('asks for a budget when none exists yet', function () {
    $this->get(route('ledger.index'))
        ->assertOk()
        ->assertSee('No budget yet');
});

it('is linked in the sidebar once the route exists', function () {
    [$budget] = ledgerSeed();

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('ledger.index'))
        ->assertOk()
        ->assertSee(route('ledger.index'))
        ->assertDontSee('<em>soon</em>', false);
});

it('groups the item dropdown by category path', function () {
    [$budget] = ledgerSeed();

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('ledger.index'))
        ->assertOk()
        ->assertSee('<optgroup label="MOOE">', false)
        ->assertSee('Office Supplies');
});

/*
|--------------------------------------------------------------------------
| Item ledger: running balance
|--------------------------------------------------------------------------
*/

it('shows the allocation first, then each expense with a running balance', function () {
    [$budget, $item] = ledgerSeed();

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('ledger.index', ['subcategory_id' => $item->id]))
        ->assertOk()
        ->assertSee('Allocation (opening balance)')
        ->assertSee('Voucher')
        ->assertSee('V-1')
        ->assertSee('CHK-5')
        ->assertSee('National Bookstore')
        ->assertSee('₱10,000.00')   // allocated
        ->assertSee('₱7,500.00')    // spent
        ->assertSee('₱2,500.00')    // balance
        ->assertViewHas('rows', fn ($page) => $page->count() === 3
            && $page->pluck('running_balance')->all() === [9000.0, 6500.0, 2500.0])
        ->assertViewHas('totals', fn ($totals) => $totals['rows'] === 3 && $totals['spent'] === 7500.0);
});

it('orders expenses of the same day by when they were recorded', function () {
    [$budget, $item] = ledgerFixture();

    ledgerSpend($item, 300, '2026-04-01', 'bank', ['description' => 'First of the day']);
    ledgerSpend($item, 200, '2026-04-01', 'bank', ['description' => 'Second of the day']);

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('ledger.index', ['subcategory_id' => $item->id]))
        ->assertViewHas('rows', fn ($page) => $page->pluck('description')->all() === ['First of the day', 'Second of the day']
            && $page->pluck('running_balance')->all() === [9700.0, 9500.0]);
});

it('ends on the same balance the budget page shows for the item', function () {
    [$budget, $item] = ledgerSeed();

    $entries = $item->ledgerEntries();

    expect($entries->last()->running_balance)->toEqual($item->fresh()->balance)
        ->and($entries->last()->running_balance)->toEqual(2500.0);
});

/*
|--------------------------------------------------------------------------
| Filters and paging keep the true balance
|--------------------------------------------------------------------------
*/

it('keeps the true running balance when the ledger is filtered', function () {
    [$budget, $item] = ledgerSeed();
    $this->withSession(['budget_id' => $budget->id]);

    // cash only: the Feb 05 expense still shows the balance after Feb 01 and Feb 05 (6,500)
    $this->get(route('ledger.index', ['subcategory_id' => $item->id, 'payment_method' => 'cash_on_hand']))
        ->assertOk()
        ->assertSee('Printer ink')
        ->assertDontSee('Bond paper')
        ->assertDontSee('Allocation (opening balance)')
        ->assertViewHas('rows', fn ($page) => $page->count() === 1 && $page->first()->running_balance === 6500.0)
        ->assertViewHas('totals', fn ($totals) => $totals['rows'] === 1 && $totals['spent'] === 2500.0);

    // March only: balance after all three expenses
    $this->get(route('ledger.index', ['subcategory_id' => $item->id, 'date_from' => '2026-03-01', 'date_to' => '2026-03-31']))
        ->assertViewHas('rows', fn ($page) => $page->count() === 1 && $page->first()->running_balance === 2500.0)
        ->assertViewHas('item', fn ($shown) => $shown->balance === 2500.0); // the item's own balance is not filtered

    // search by payee, voucher number and check number
    $this->get(route('ledger.index', ['subcategory_id' => $item->id, 'search' => 'bookstore']))
        ->assertViewHas('rows', fn ($page) => $page->count() === 1 && $page->first()->running_balance === 9000.0);

    $this->get(route('ledger.index', ['subcategory_id' => $item->id, 'search' => 'V-1']))
        ->assertViewHas('rows', fn ($page) => $page->count() === 1);

    $this->get(route('ledger.index', ['subcategory_id' => $item->id, 'search' => 'CHK-5']))
        ->assertViewHas('rows', fn ($page) => $page->count() === 1 && $page->first()->running_balance === 2500.0);
});

it('says so when no expense matches the filters', function () {
    [$budget, $item] = ledgerSeed();

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('ledger.index', ['subcategory_id' => $item->id, 'search' => 'nothing like this']))
        ->assertOk()
        ->assertSee('No expenses match these filters.');
});

it('pages the ledger and continues the running balance on the next page', function () {
    [$budget, $item] = ledgerFixture();

    foreach (range(1, 25) as $day) {
        ledgerSpend($item, 100, sprintf('2026-03-%02d', $day));
    }

    $this->withSession(['budget_id' => $budget->id]);

    $this->get(route('ledger.index', ['subcategory_id' => $item->id]))
        ->assertViewHas('rows', fn ($page) => $page->count() === 20 && $page->total() === 25
            && $page->last()->running_balance === 8000.0);

    // 10,000 - 21 x 100 = 7,900 on the first row of page 2; 7,500 after all 25
    $this->get(route('ledger.index', ['subcategory_id' => $item->id, 'page' => 2]))
        ->assertViewHas('rows', fn ($page) => $page->count() === 5
            && $page->currentPage() === 2
            && $page->first()->running_balance === 7900.0
            && $page->last()->running_balance === 7500.0)
        ->assertDontSee('row-opening', false); // the screen shows the allocation row on the first page only (the printed report lists everything)
});

it('rejects an end date before the start date', function () {
    [$budget, $item] = ledgerSeed();

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('ledger.index', ['subcategory_id' => $item->id, 'date_from' => '2026-03-01', 'date_to' => '2026-02-01']))
        ->assertSessionHasErrors('date_to');
});

/*
|--------------------------------------------------------------------------
| Overspent items
|--------------------------------------------------------------------------
*/

it('flags an overspent item instead of showing the negative balance silently', function () {
    [$budget, $item] = ledgerFixture();
    $item->update(['allocated_amount' => 1000]);

    ledgerSpend($item, 600, '2026-02-01');
    ledgerSpend($item, 900, '2026-02-02');

    $this->withSession(['budget_id' => $budget->id]);

    $this->get(route('ledger.index', ['subcategory_id' => $item->id]))
        ->assertOk()
        ->assertSee('Overspent')
        ->assertSee('Overspent by')
        ->assertViewHas('rows', fn ($page) => $page->pluck('running_balance')->all() === [400.0, -500.0]);

    // the summary flags it as well
    $this->get(route('ledger.index'))
        ->assertOk()
        ->assertSee('Overspent')
        ->assertViewHas('summary', fn ($summary) => $summary['overspent'] === 1 && $summary['balance'] === -500.0);
});

it('does not flag an item that stays within its allocation', function () {
    [$budget, $item] = ledgerSeed();

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('ledger.index', ['subcategory_id' => $item->id]))
        ->assertOk()
        ->assertDontSee('Overspent');
});

/*
|--------------------------------------------------------------------------
| Summary of items and categories
|--------------------------------------------------------------------------
*/

it('summarises every item with allocated, spent, balance and percent used', function () {
    [$budget, $item, $category] = ledgerSeed();
    $category->subcategories()->create(['name' => 'Travel', 'allocated_amount' => 5000]);

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('ledger.index'))
        ->assertOk()
        ->assertSee('Office Supplies')
        ->assertSee('Travel')
        ->assertSee('₱15,000.00')   // allocated
        ->assertSee('₱7,500.00')    // spent and balance
        ->assertSee('50%')
        ->assertViewHas('summary', fn ($summary) => $summary['items'] === 2
            && $summary['allocated'] === 15000.0
            && $summary['spent'] === 7500.0
            && $summary['balance'] === 7500.0
            && $summary['percent'] === 50.0
            && $summary['overspent'] === 0)
        ->assertViewHas('summaryRows', fn ($page) => $page->total() === 2
            && $page->first()->spent === 7500.0
            && $page->first()->utilization_percent === 75.0);
});

it('includes nested categories when a category is picked', function () {
    [$budget, $item, $category] = ledgerFixture();

    $nested = $budget->categories()->create(['name' => 'Sports Development', 'parent_id' => $category->id]);
    $deep   = $budget->categories()->create(['name' => 'Leagues', 'parent_id' => $nested->id]);
    $other  = $budget->categories()->create(['name' => 'Capital Outlay']);

    $nestedItem = $nested->subcategories()->create(['name' => 'Basketball Uniforms', 'allocated_amount' => 3000]);
    $deepItem   = $deep->subcategories()->create(['name' => 'Referee Fees', 'allocated_amount' => 2000]);
    $other->subcategories()->create(['name' => 'Laptop', 'allocated_amount' => 9000]);

    ledgerSpend($nestedItem, 500, '2026-02-01');
    ledgerSpend($deepItem, 250, '2026-02-02');

    $this->withSession(['budget_id' => $budget->id]);

    // the top category: its own item and everything nested at any depth (not the other category)
    $this->get(route('ledger.index', ['category_id' => $category->id]))
        ->assertOk()
        ->assertViewHas('summaryRows', fn ($page) => $page->pluck('name')->all() === ['Office Supplies', 'Basketball Uniforms', 'Referee Fees'])
        ->assertViewHas('summary', fn ($summary) => $summary['items'] === 3
            && $summary['allocated'] === 15000.0
            && $summary['spent'] === 750.0
            && $summary['balance'] === 14250.0);

    // a nested category only brings its own subtree
    $this->get(route('ledger.index', ['category_id' => $nested->id]))
        ->assertOk()
        ->assertViewHas('summaryRows', fn ($page) => $page->pluck('name')->all() === ['Basketball Uniforms', 'Referee Fees'])
        ->assertViewHas('summary', fn ($summary) => $summary['items'] === 2 && $summary['spent'] === 750.0);

    // a category without nested categories brings only its own items
    $this->get(route('ledger.index', ['category_id' => $other->id]))
        ->assertViewHas('summaryRows', fn ($page) => $page->pluck('name')->all() === ['Laptop']);
});

/*
|--------------------------------------------------------------------------
| Other budgets stay out
|--------------------------------------------------------------------------
*/

it('does not show items or spending of another budget', function () {
    [$budget] = ledgerSeed();

    $other         = Budget::create(['year' => 2025, 'type' => 'regular', 'total_budget' => 50000]);
    $otherCategory = $other->categories()->create(['name' => 'Old Programs']);
    $otherItem     = $otherCategory->subcategories()->create(['name' => 'Foreign Item', 'allocated_amount' => 7777]);

    ledgerSpend($otherItem, 321, '2025-05-01');

    $this->withSession(['budget_id' => $budget->id]);

    // not in the summary, the dropdown or the totals
    $this->get(route('ledger.index'))
        ->assertOk()
        ->assertDontSee('Foreign Item')
        ->assertDontSee('Old Programs')
        ->assertViewHas('summary', fn ($summary) => $summary['items'] === 1 && $summary['spent'] === 7500.0);

    // asking for it directly sends the treasurer back with a message
    $this->get(route('ledger.index', ['subcategory_id' => $otherItem->id]))
        ->assertRedirect(route('ledger.index'))
        ->assertSessionHasErrors('ledger');

    $this->get(route('ledger.index', ['category_id' => $otherCategory->id]))
        ->assertRedirect(route('ledger.index'))
        ->assertSessionHasErrors('ledger');

    // and the other budget's own ledger works when that budget is the current one
    $this->withSession(['budget_id' => $other->id])
        ->get(route('ledger.index', ['subcategory_id' => $otherItem->id]))
        ->assertOk()
        ->assertSee('Foreign Item')
        ->assertDontSee('Office Supplies');
});
