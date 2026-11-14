<?php

use App\Models\Budget;
use App\Models\FundMovement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A budget with one category, one item and $expenses small expenses in different months. */
function printSeed(int $expenses = 20): array
{
    $budget = Budget::create([
        'year' => 2026, 'type' => 'regular', 'total_budget' => 100000,
        'opening_bank_balance' => 50000, 'opening_cash_balance' => 10000,
    ]);

    $category = $budget->categories()->create(['name' => 'MOOE']);
    $item     = $category->subcategories()->create(['name' => 'Office Supplies', 'allocated_amount' => 10000]);

    for ($i = 1; $i <= $expenses; $i++) {
        Transaction::create([
            'date'           => sprintf('2026-%02d-%02d', ($i % 12) + 1, ($i % 27) + 1),
            'description'    => "Printed expense {$i}",
            'category_id'    => $category->id,
            'subcategory_id' => $item->id,
            'amount'         => 100 + $i,
            'payment_method' => $i % 2 ? 'bank' : 'cash_on_hand',
        ]);
    }

    FundMovement::create(['budget_id' => $budget->id, 'date' => '2026-01-05', 'type' => 'receipt', 'account' => 'bank', 'amount' => 5000, 'reference' => 'OR-1']);
    FundMovement::create(['budget_id' => $budget->id, 'date' => '2026-01-10', 'type' => 'withdrawal', 'amount' => 2000, 'reference' => 'CHK-9']);

    return [$budget, $item, $category];
}

beforeEach(function () {
    $this->actingAs(new User(['name' => 'SK Treasurer', 'email' => 'treasurer@example.test']));
});

it('gives every page a printed report that replaces the screen on paper', function (string $route, string $title) {
    [$budget] = printSeed();

    $this->withSession(['budget_id' => $budget->id])
        ->get(route($route))
        ->assertOk()
        ->assertSee('has-print-report', false)
        ->assertSee('class="print-report"', false)
        ->assertSee('class="pr-title"', false)
        ->assertSee($title)
        ->assertSee('pr-kpi', false);
})->with([
    'dashboard'      => ['dashboard', 'Budget Overview'],
    'budget'         => ['budget.index', 'Budget & Allocation'],
    'transactions'   => ['transactions.index', 'Transactions Report'],
    'fund movements' => ['fund-movements.index', 'Fund Movements Report'],
    'journal'        => ['journal.index', 'Journal'],
    'ledger'         => ['ledger.index', 'Ledger'],
]);

it('prints every transaction that matches the filters, not only the page on screen', function () {
    [$budget] = printSeed(20); // the screen shows 15 per page

    $html = $this->withSession(['budget_id' => $budget->id])->get(route('transactions.index'))->assertOk()->getContent();
    $print = substr($html, strpos($html, 'class="print-report"'));

    expect($print)->toMatch('/Printed expense 1\b/')->toMatch('/Printed expense 20\b/');
    expect(preg_match_all('/Printed expense \d+/', $print))->toBeGreaterThanOrEqual(20);
});

it('does not print the "Showing x of y" line or the paging links', function () {
    [$budget] = printSeed();

    $html  = $this->withSession(['budget_id' => $budget->id])->get(route('transactions.index'))->getContent();
    $print = substr($html, strpos($html, 'class="print-report"'));

    expect($print)->not->toContain('Showing ')->not->toContain('class="pagination"');
});

it('applies the filters to the printed transactions report', function () {
    [$budget] = printSeed(20);

    $html  = $this->withSession(['budget_id' => $budget->id])->get(route('transactions.index', ['payment_method' => 'bank']))->getContent();
    $print = substr($html, strpos($html, 'class="print-report"'));

    expect($print)->toContain('Paid from: Bank')->toMatch('/Printed expense 1\b/')->not->toMatch('/Printed expense 2\b/');
});

it('prints the journal with every entry and its balances', function () {
    [$budget] = printSeed(20);

    $html  = $this->withSession(['budget_id' => $budget->id])->get(route('journal.index'))->getContent();
    $print = substr($html, strpos($html, 'class="print-report"'));

    expect($print)->toContain('Opening balances')->toMatch('/Printed expense 20\b/')->toContain('OR-1')->toContain('pr-lines');
});

it('prints one item ledger with a running balance and a summary of all items', function () {
    [$budget, $item] = printSeed(5);

    $html  = $this->withSession(['budget_id' => $budget->id])->get(route('ledger.index', ['subcategory_id' => $item->id]))->getContent();
    $print = substr($html, strpos($html, 'class="print-report"'));
    expect($print)->toContain('Item Ledger')->toContain('Allocation (opening balance)')->toContain('Balance after each expense');

    $html  = $this->withSession(['budget_id' => $budget->id])->get(route('ledger.index'))->getContent();
    $print = substr($html, strpos($html, 'class="print-report"'));
    expect($print)->toContain('All items')->toContain('Office Supplies')->toContain('MOOE');
});

it('does not force one paper size, so the report fits Letter and A4', function () {
    $css = file_get_contents(public_path('css/print-report.css')) . file_get_contents(public_path('css/custom.css'));

    expect($css)->toContain('size: auto')
        ->not->toContain('size: A4')
        ->not->toMatch('/@page\s*\{\s*size:\s*letter/');
});

it('gives a kagawad no printed report at all', function () {
    [$budget] = printSeed();

    $viewer = User::factory()->create();
    $viewer->forceFill(['role' => 'kagawad'])->save();

    $this->actingAs($viewer)->withSession(['budget_id' => $budget->id])
        ->get(route('transactions.index'))
        ->assertOk()
        ->assertSee('class="no-printing"', false)
        ->assertDontSee('class="print-report"', false);
});

it('draws pages without a budget and without data without errors', function () {
    foreach (['dashboard', 'budget.index', 'transactions.index', 'fund-movements.index', 'journal.index', 'ledger.index'] as $route) {
        $this->get(route($route))->assertOk();
    }

    $budget = Budget::create(['year' => 2026, 'type' => 'regular', 'total_budget' => 1000, 'opening_bank_balance' => 0, 'opening_cash_balance' => 0]);

    foreach (['dashboard', 'budget.index', 'transactions.index', 'fund-movements.index', 'journal.index', 'ledger.index'] as $route) {
        $this->withSession(['budget_id' => $budget->id])->get(route($route))->assertOk();
    }
});
