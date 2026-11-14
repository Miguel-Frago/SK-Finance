<?php

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** A 2026 budget (₱50,000 bank, ₱10,000 cash) with one ₱20,000 item. Returns [budget, item]. */
function dashboardFixture(): array
{
    $budget = Budget::create([
        'year'                 => 2026,
        'type'                 => 'regular',
        'total_budget'         => 100000,
        'opening_bank_balance' => 50000,
        'opening_cash_balance' => 10000,
    ]);

    $category = $budget->categories()->create(['name' => 'MOOE']);
    $item     = $category->subcategories()->create(['name' => 'Office Supplies', 'allocated_amount' => 20000]);

    return [$budget, $item];
}

function dashboardExpense($item, float $amount, string $date, string $method = 'bank'): Transaction
{
    return Transaction::create([
        'date'           => $date,
        'description'    => 'Dashboard test expense',
        'category_id'    => $item->category_id,
        'subcategory_id' => $item->id,
        'amount'         => $amount,
        'payment_method' => $method,
    ]);
}

beforeEach(function () {
    // Unsaved user: the project has no users migration, and the tests do not need a users row.
    $this->actingAs(new User(['name' => 'SK Treasurer', 'email' => 'treasurer@example.test']));
});

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/

it('asks for a budget when none exists yet', function () {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('No budget has been set up yet');
});

it('shows the summary cards for the current budget', function () {
    [$budget, $item] = dashboardFixture();
    dashboardExpense($item, 4000, '2026-03-10', 'bank');
    dashboardExpense($item, 1000, '2026-03-12', 'cash_on_hand');

    // bank 50,000 - 4,000 = 46,000 / cash 10,000 - 1,000 = 9,000 / total 55,000 / left 95,000
    $this->withSession(['budget_id' => $budget->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('₱100,000.00')
        ->assertSee('₱5,000.00')
        ->assertSee('₱95,000.00')
        ->assertSee('₱46,000.00')
        ->assertSee('₱9,000.00')
        ->assertSee('₱55,000.00')
        ->assertSee('Dashboard test expense');
});

it('totals the spending per month', function () {
    [$budget, $item] = dashboardFixture();
    dashboardExpense($item, 1000, '2026-03-05');
    dashboardExpense($item, 500, '2026-03-20');
    dashboardExpense($item, 200, '2026-06-01');

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('months', fn ($months) => count($months) === 12
            && $months[0]['total'] === 0.0
            && $months[2]['total'] === 1500.0
            && $months[5]['total'] === 200.0
            && $months[2]['pct'] == 100
            && $months[5]['pct'] == 13.3);
});

it('lists items that used 80 percent or more of their allocation', function () {
    [$budget, $item] = dashboardFixture();
    $other = $item->category->subcategories()->create(['name' => 'Snacks', 'allocated_amount' => 10000]);

    dashboardExpense($item, 17000, '2026-02-01'); // 85% of 20,000
    dashboardExpense($other, 1000, '2026-02-02'); // 10% of 10,000

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Items nearly used up')
        ->assertViewHas('nearlyUsed', fn ($items) => $items->count() === 1 && $items->first()->name === 'Office Supplies');
});

it('shows a withdrawal as a transfer that does not change the budget left', function () {
    [$budget] = dashboardFixture();
    $budget->fundMovements()->create(['date' => '2026-04-01', 'type' => 'withdrawal', 'amount' => 5000]);

    // bank 45,000 / cash 15,000 / total still 60,000 / nothing spent
    $this->withSession(['budget_id' => $budget->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('₱45,000.00')
        ->assertSee('₱15,000.00')
        ->assertSee('₱60,000.00')
        ->assertViewHas('left', 100000.0)
        ->assertViewHas('spent', 0.0);
});
