<?php

use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Unsaved user, like the other feature tests
    $this->actingAs(new User(['name' => 'SK Treasurer', 'email' => 'treasurer@example.test']));
});

/** A budget with one item and two expenses: one with a receipt image, one without. */
function reportSeed(): Budget
{
    $budget = Budget::create([
        'year' => 2026, 'type' => 'regular', 'total_budget' => 100000,
        'opening_bank_balance' => 10000, 'opening_cash_balance' => 5000,
    ]);

    $item = $budget->categories()->create(['name' => 'MOOE'])
        ->subcategories()->create(['name' => 'Office Supplies', 'allocated_amount' => 20000]);

    $withReceipt = Transaction::create([
        'date' => '2026-02-01', 'description' => 'Bond paper', 'category_id' => $item->category_id, 'subcategory_id' => $item->id,
        'amount' => 1000, 'payment_method' => 'bank', 'voucher_number' => 'V-1',
    ]);
    // receipts live in their own table now (an expense can have several)
    $withReceipt->attachments()->create(['path' => 'receipts/2026/bond-paper.jpg', 'original_name' => 'bond-paper.jpg', 'size' => 1234]);
    Transaction::create([
        'date' => '2026-02-02', 'description' => 'Ink refill', 'category_id' => $item->category_id, 'subcategory_id' => $item->id,
        'amount' => 500, 'payment_method' => 'cash_on_hand',
    ]);

    return $budget;
}

it('shows the transactions and the receipt of each one', function () {
    reportSeed();

    $this->get(route('reports.index'))
        ->assertOk()
        ->assertSee('Statement of Expenses')
        ->assertSee('Bond paper')
        ->assertSee('Ink refill')
        ->assertSee('bond-paper.jpg')
        ->assertSee('Supporting documents');
});

it('can show only the expenses that are missing a receipt', function () {
    reportSeed();

    $this->get(route('reports.index', ['evidence' => 'without']))
        ->assertOk()
        ->assertSee('Ink refill')
        ->assertDontSee('Bond paper');
});

it('can hide the evidence pages', function () {
    reportSeed();

    $this->get(route('reports.index', ['show' => 'none']))
        ->assertOk()
        ->assertDontSee('Supporting documents');
});

it('explains that a budget is needed first', function () {
    $this->get(route('reports.index'))->assertOk()->assertSee('No budget yet');
});
