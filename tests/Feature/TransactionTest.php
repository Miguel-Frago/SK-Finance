<?php

use App\Models\Budget;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/** A 2026 budget with one category and one ₱20,000 item. Returns [budget, item, category]. */
function transactionFixture(array $budget = []): array
{
    $budget = Budget::create(array_merge([
        'year'                 => 2026,
        'type'                 => 'regular',
        'total_budget'         => 100000,
        'opening_bank_balance' => 50000,
        'opening_cash_balance' => 10000,
    ], $budget));

    $category = $budget->categories()->create(['name' => 'MOOE']);
    $item     = $category->subcategories()->create(['name' => 'Office Supplies', 'allocated_amount' => 20000]);

    return [$budget, $item, $category];
}

/** Form data for POST/PUT transactions. */
function transactionPayload(Subcategory $item, array $overrides = []): array
{
    return array_merge([
        'date'           => '2026-03-15',
        'description'    => 'Bond paper',
        'payee'          => 'National Bookstore',
        'category_id'    => $item->category_id,
        'subcategory_id' => $item->id,
        'amount'         => 1500,
        'payment_method' => 'bank',
        'voucher_number' => 'V-001',
        'check_number'   => 'CHK-100',
    ], $overrides);
}

/** Insert an expense directly, bypassing the form rules. */
function makeExpense(Subcategory $item, float $amount, string $method = 'bank', array $attributes = []): Transaction
{
    return Transaction::create(array_merge([
        'date'           => '2026-03-10',
        'description'    => 'Test expense',
        'category_id'    => $item->category_id,
        'subcategory_id' => $item->id,
        'amount'         => $amount,
        'payment_method' => $method,
    ], $attributes));
}

beforeEach(function () {
    // Sign in as an unsaved user: the project has no users migration, and the tests do not need a users row.
    $this->actingAs(new User(['name' => 'SK Treasurer', 'email' => 'treasurer@example.test']));
});

/*
|--------------------------------------------------------------------------
| Creating a transaction
|--------------------------------------------------------------------------
*/

it('records an expense and sets the category from the item', function () {
    [$budget, $item] = transactionFixture();

    $this->post(route('transactions.store'), transactionPayload($item))
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHasNoErrors();

    $transaction = Transaction::firstOrFail();

    expect($transaction->category_id)->toEqual($item->category_id)
        ->and($transaction->subcategory_id)->toEqual($item->id)
        ->and((float) $transaction->amount)->toEqual(1500.0)
        ->and($transaction->check_number)->toBe('CHK-100');
});

it('updates the spent, balance and account figures of the budget', function () {
    [$budget, $item] = transactionFixture();

    $this->post(route('transactions.store'), transactionPayload($item, ['amount' => 1500]))
        ->assertSessionHasNoErrors();

    expect($item->fresh()->spent)->toEqual(1500.0)
        ->and($item->fresh()->balance)->toEqual(18500.0)
        ->and($budget->fresh()->total_spent)->toEqual(1500.0)
        ->and($budget->fresh()->bank_balance)->toEqual(48500.0)
        ->and($budget->fresh()->cash_balance)->toEqual(10000.0);
});

it('shows the spending on the budget page', function () {
    [$budget, $item] = transactionFixture();
    makeExpense($item, 1500);

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('budget.index'))
        ->assertOk()
        ->assertSee('₱1,500.00')
        ->assertSee('₱18,500.00');
});

it('drops the check number when the payment is from cash', function () {
    [$budget, $item] = transactionFixture();

    $this->post(route('transactions.store'), transactionPayload($item, ['payment_method' => 'cash_on_hand']))
        ->assertSessionHasNoErrors();

    expect(Transaction::firstOrFail()->check_number)->toBeNull();
});

it('rejects a date outside the budget year', function () {
    [$budget, $item] = transactionFixture();

    $this->post(route('transactions.store'), transactionPayload($item, ['date' => '2027-01-02']))
        ->assertSessionHasErrors('date');

    expect(Transaction::count())->toBe(0);
});

it('rejects an amount of zero or less', function () {
    [$budget, $item] = transactionFixture();

    $this->post(route('transactions.store'), transactionPayload($item, ['amount' => 0]))
        ->assertSessionHasErrors('amount');

    $this->post(route('transactions.store'), transactionPayload($item, ['amount' => -50]))
        ->assertSessionHasErrors('amount');

    expect(Transaction::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Overspending an item
|--------------------------------------------------------------------------
*/

it('rejects an expense bigger than the remaining balance of the item', function () {
    [$budget, $item] = transactionFixture(['opening_bank_balance' => 100000]);

    $this->post(route('transactions.store'), transactionPayload($item, ['amount' => 20000.01]))
        ->assertSessionHasErrors('amount');

    expect(Transaction::count())->toBe(0);
});

it('counts earlier spending when checking the remaining balance', function () {
    [$budget, $item] = transactionFixture(['opening_bank_balance' => 100000]);
    makeExpense($item, 19000);

    $this->post(route('transactions.store'), transactionPayload($item, ['amount' => 1500]))
        ->assertSessionHasErrors('amount');

    // Exactly what is left is fine
    $this->post(route('transactions.store'), transactionPayload($item, ['amount' => 1000]))
        ->assertSessionHasNoErrors();

    expect(Transaction::count())->toBe(2)
        ->and($item->fresh()->balance)->toEqual(0.0);
});

/*
|--------------------------------------------------------------------------
| Bank / cash cannot go negative
|--------------------------------------------------------------------------
*/

it('rejects a bank payment that would make the bank balance negative', function () {
    [$budget, $item] = transactionFixture(['opening_bank_balance' => 1000, 'opening_cash_balance' => 10000]);

    $this->post(route('transactions.store'), transactionPayload($item, ['amount' => 1500, 'payment_method' => 'bank']))
        ->assertSessionHasErrors('payment_method');

    expect(Transaction::count())->toBe(0);

    // The same payment from cash is fine
    $this->post(route('transactions.store'), transactionPayload($item, ['amount' => 1500, 'payment_method' => 'cash_on_hand']))
        ->assertSessionHasNoErrors();

    expect(Transaction::count())->toBe(1);
});

it('rejects a cash payment that would make cash on hand negative', function () {
    [$budget, $item] = transactionFixture(['opening_bank_balance' => 50000, 'opening_cash_balance' => 500]);

    $this->post(route('transactions.store'), transactionPayload($item, ['amount' => 1500, 'payment_method' => 'cash_on_hand']))
        ->assertSessionHasErrors('payment_method');

    expect(Transaction::count())->toBe(0);

    // The same payment from the bank is fine
    $this->post(route('transactions.store'), transactionPayload($item, ['amount' => 1500, 'payment_method' => 'bank']))
        ->assertSessionHasNoErrors();

    expect(Transaction::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Category / item mismatch
|--------------------------------------------------------------------------
*/

it('rejects an item that does not belong to the chosen category', function () {
    [$budget, $item] = transactionFixture();

    $other = $budget->categories()->create(['name' => 'Capital Outlay']);
    $other->subcategories()->create(['name' => 'Laptop', 'allocated_amount' => 5000]);

    $this->post(route('transactions.store'), transactionPayload($item, ['category_id' => $other->id]))
        ->assertSessionHasErrors('subcategory_id');

    expect(Transaction::count())->toBe(0);
});

it('does not let an edit move the expense to an item of another budget', function () {
    [$budget, $item] = transactionFixture();
    $transaction = makeExpense($item, 1000);

    $otherBudget   = Budget::create(['year' => 2026, 'type' => 'supplemental', 'total_budget' => 50000, 'opening_bank_balance' => 50000]);
    $otherCategory = $otherBudget->categories()->create(['name' => 'Supplemental MOOE']);
    $otherItem     = $otherCategory->subcategories()->create(['name' => 'Supplies', 'allocated_amount' => 5000]);

    $this->put(route('transactions.update', $transaction), transactionPayload($otherItem))
        ->assertSessionHasErrors('subcategory_id');

    expect($transaction->fresh()->subcategory_id)->toEqual($item->id);
});

/*
|--------------------------------------------------------------------------
| Editing and deleting
|--------------------------------------------------------------------------
*/

it('lets an expense be edited without counting its own amount twice', function () {
    // The bank holds exactly what the expense used, and the item has ₱5,000 left
    [$budget, $item] = transactionFixture(['opening_bank_balance' => 15000]);
    $transaction = makeExpense($item, 15000);

    $this->put(route('transactions.update', $transaction), transactionPayload($item, ['amount' => 15000, 'description' => 'Corrected']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('transactions.index'));

    expect($transaction->fresh()->description)->toBe('Corrected');

    // One centavo more than the bank can cover is still rejected
    $this->put(route('transactions.update', $transaction), transactionPayload($item, ['amount' => 15000.01]))
        ->assertSessionHasErrors('payment_method');
});

it('keeps the receipt file when a transaction is voided, and takes it out of the balances', function () {
    Storage::fake('public');
    [$budget, $item] = transactionFixture();

    $receipt = UploadedFile::fake()->create('receipt.pdf', 120, 'application/pdf');

    $this->post(route('transactions.store'), transactionPayload($item) + ['receipts' => [$receipt]])
        ->assertSessionHasNoErrors();

    $transaction = Transaction::firstOrFail();
    $path        = $transaction->attachments()->firstOrFail()->path;

    Storage::disk('public')->assertExists($path);
    expect($budget->fresh()->bank_balance)->toEqual(48500.0);

    $this->post(route('transactions.void', $transaction), ['void_reason' => 'Entered twice by mistake'])->assertRedirect();

    // the evidence stays; the money no longer counts
    Storage::disk('public')->assertExists($path);
    expect(Transaction::count())->toBe(0)
        ->and(Transaction::withoutGlobalScope('notVoided')->count())->toBe(1)
        ->and($budget->fresh()->bank_balance)->toEqual(50000.0);
});

it('rejects a receipt that is not an image or a PDF', function () {
    Storage::fake('public');
    [$budget, $item] = transactionFixture();

    $file = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

    $this->post(route('transactions.store'), transactionPayload($item) + ['receipts' => [$file]])
        ->assertSessionHasErrors('receipts.0');

    expect(Transaction::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Pages
|--------------------------------------------------------------------------
*/

it('shows the create and edit forms', function () {
    [$budget, $item] = transactionFixture();
    $transaction = makeExpense($item, 1000);

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('transactions.create'))
        ->assertOk()
        ->assertSee('Record expense');

    $this->get(route('transactions.edit', $transaction))
        ->assertOk()
        ->assertSee('Edit transaction');
});

it('filters the list and totals only the matching rows', function () {
    [$budget, $item] = transactionFixture();
    makeExpense($item, 1000, 'bank', ['description' => 'Bond paper']);
    makeExpense($item, 250, 'cash_on_hand', ['description' => 'Tarpaulin']);

    $this->withSession(['budget_id' => $budget->id]);

    $this->get(route('transactions.index', ['search' => 'Bond']))
        ->assertOk()
        ->assertSee('Bond paper')
        ->assertDontSee('Tarpaulin')
        ->assertSee('₱1,000.00');

    $this->get(route('transactions.index', ['payment_method' => 'cash_on_hand']))
        ->assertOk()
        ->assertSee('Tarpaulin')
        ->assertDontSee('Bond paper');

    $this->get(route('transactions.index', ['date_from' => '2026-04-01']))
        ->assertOk()
        ->assertSee('No transactions match these filters.');
});

it('includes nested categories when filtering by a parent category', function () {
    [$budget, $item, $category] = transactionFixture();

    $child     = $budget->categories()->create(['name' => 'Training', 'parent_id' => $category->id]);
    $childItem = $child->subcategories()->create(['name' => 'Seminar Fees', 'allocated_amount' => 5000]);

    makeExpense($childItem, 700, 'bank', ['description' => 'Seminar registration']);
    makeExpense($item, 300, 'bank', ['description' => 'Pens']);

    $this->withSession(['budget_id' => $budget->id])
        ->get(route('transactions.index', ['category_id' => $category->id]))
        ->assertOk()
        ->assertSee('Seminar registration')
        ->assertSee('Pens');

    $this->get(route('transactions.index', ['category_id' => $child->id]))
        ->assertOk()
        ->assertSee('Seminar registration')
        ->assertDontSee('Pens');
});
