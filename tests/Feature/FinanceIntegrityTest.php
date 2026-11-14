<?php

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\BankReconciliation;
use App\Models\Barangay;
use App\Models\Budget;
use App\Models\FundMovement;
use App\Models\PeriodLock;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Finance integrity: void instead of delete, the quarter lock, bank reconciliation
| (helpers msgUser, msgAsUser, msgAdmin come from MessagingTest.php)
|--------------------------------------------------------------------------
*/

/** A real Treasurer and Chairman of one barangay, a 2026 budget with one ₱20,000 item, signed in as the Treasurer. */
function finSetup(int $barangay = 0): array
{
    $treasurer = msgUser('treasurer', $barangay);
    $chairman = msgUser('chairman', $barangay);
    msgAsUser($treasurer);

    $budget = Budget::create(['year' => 2026, 'type' => 'regular', 'total_budget' => 100000, 'opening_bank_balance' => 50000, 'opening_cash_balance' => 10000]);
    $category = $budget->categories()->create(['name' => 'MOOE']);
    $item = $category->subcategories()->create(['name' => 'Office Supplies', 'allocated_amount' => 20000]);

    return [$treasurer, $chairman, $budget, $item];
}

function finExpense($item, string $date = '2026-03-10', float $amount = 1000): Transaction
{
    return Transaction::create(['date' => $date, 'description' => 'Bond paper', 'category_id' => $item->category_id, 'subcategory_id' => $item->id, 'amount' => $amount, 'payment_method' => 'bank']);
}

function finPayload($item, array $o = []): array
{
    return array_merge(['date' => '2026-03-15', 'description' => 'Folders', 'payee' => 'Bookstore', 'category_id' => $item->category_id, 'subcategory_id' => $item->id, 'amount' => 500, 'payment_method' => 'bank'], $o);
}

beforeEach(fn () => Carbon::setTestNow('2026-10-29 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

// ---------------------------------------------------------------- void instead of delete

it('has no way to delete an expense or a fund movement any more', function () {
    [, , , $item] = finSetup();
    $tx = finExpense($item);

    expect(\Illuminate\Support\Facades\Route::has('transactions.destroy'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Route::has('fund-movements.destroy'))->toBeFalse();
    $this->delete('/transactions/' . $tx->id)->assertStatus(405);
});

it('voids an expense: it stays in the books with who, when and why, and leaves every total', function () {
    [$treasurer, , $budget, $item] = finSetup();
    $tx = finExpense($item, '2026-03-10', 1000);
    expect($budget->fresh()->bank_balance)->toEqual(49000.0);

    $this->post(route('transactions.void', $tx), ['void_reason' => 'Entered twice by mistake'])->assertRedirect()->assertSessionHas('success');

    $kept = Transaction::withoutGlobalScope('notVoided')->findOrFail($tx->id);
    expect($kept->isVoided())->toBeTrue()
        ->and($kept->void_reason)->toBe('Entered twice by mistake')
        ->and((int) $kept->voided_by)->toBe($treasurer->id)
        ->and($budget->fresh()->bank_balance)->toEqual(50000.0)
        ->and($item->fresh()->spent ?? 0)->toEqual(0.0)
        ->and(Transaction::count())->toBe(0);

    expect(ActivityLog::where('action', 'voided')->where('subject_id', $tx->id)->exists())->toBeTrue();
});

it('needs a real reason to void', function () {
    [, , , $item] = finSetup();
    $tx = finExpense($item);

    $this->post(route('transactions.void', $tx), [])->assertSessionHasErrors('void_reason');
    $this->post(route('transactions.void', $tx), ['void_reason' => 'oops'])->assertSessionHasErrors('void_reason');
    expect(Transaction::count())->toBe(1);
});

it('cannot edit or void a voided expense again', function () {
    [, , , $item] = finSetup();
    $tx = finExpense($item);
    $this->post(route('transactions.void', $tx), ['void_reason' => 'Entered twice by mistake']);

    $this->get(route('transactions.edit', $tx->id))->assertNotFound();
    $this->post(route('transactions.void', $tx->id), ['void_reason' => 'Voiding it a second time'])->assertNotFound();
});

it('shows voided records only when asked, struck through with the reason, and never in the totals', function () {
    [, , $budget, $item] = finSetup();
    $keep = finExpense($item, '2026-03-10', 1000);
    $gone = finExpense($item, '2026-03-11', 700);
    $this->post(route('transactions.void', $gone), ['void_reason' => 'Wrong amount typed in']);

    $this->withSession(['budget_id' => $budget->id])->get(route('transactions.index'))->assertOk()->assertDontSee('Wrong amount typed in');
    $this->withSession(['budget_id' => $budget->id])->get(route('transactions.index', ['voided' => 1]))->assertOk()
        ->assertSee('Wrong amount typed in')->assertSee('Voided')->assertSee('Hide voided');

    // the budget never counts it, with the list on or off
    expect($budget->fresh()->bank_balance)->toEqual(49000.0);
});

it('voids a fund movement only when the balances can take it, with a reason', function () {
    [, , $budget] = finSetup();
    $withdrawal = FundMovement::create(['budget_id' => $budget->id, 'type' => 'withdrawal', 'date' => '2026-03-05', 'amount' => 4000, 'description' => 'Cash for the event']);
    expect($budget->fresh()->cash_balance)->toEqual(14000.0);

    $this->post(route('fund-movements.void', $withdrawal), ['void_reason' => 'Recorded on the wrong date'])->assertSessionHasNoErrors();
    expect($budget->fresh()->cash_balance)->toEqual(10000.0)
        ->and(FundMovement::withoutGlobalScope('notVoided')->count())->toBe(1);
});

it('keeps another barangay\'s expenses out of reach', function () {
    [, , , $item] = finSetup();
    $tx = finExpense($item);

    msgAsUser(msgUser('treasurer', 1));
    $this->post(route('transactions.void', $tx->id), ['void_reason' => 'Trying another barangay'])->assertNotFound();
    expect(Transaction::withoutGlobalScopes()->find($tx->id)->voided_at)->toBeNull();
});

// ---------------------------------------------------------------- the quarter lock

function finLock(int $year = 2026, int $quarter = 1): void
{
    test()->post(route('period-locks.request', [$year, $quarter]))->assertSessionHasNoErrors();
}

it('locks a finished quarter only when the Treasurer asks and the Chairman approves', function () {
    [$treasurer, $chairman, , $item] = finSetup();

    finLock();
    expect(PeriodLock::withoutGlobalScopes()->first()->status)->toBe('lock_requested');
    // not locked yet: still open to changes
    finExpense($item, '2026-02-01');

    // the Treasurer cannot approve her own request
    $this->post(route('period-locks.approve', [2026, 1]))->assertSessionHasErrors('lock');

    msgAsUser($chairman);
    $this->post(route('period-locks.approve', [2026, 1]))->assertSessionHasNoErrors();
    expect(PeriodLock::withoutGlobalScopes()->first()->status)->toBe('locked');
});

it('does not let a quarter be locked before it has ended, or by the wrong person', function () {
    [$treasurer, $chairman] = finSetup();

    $this->post(route('period-locks.request', [2026, 4]))->assertSessionHasErrors('lock');   // Oct to Dec: still running on Oct 29
    $this->post(route('period-locks.request', [2026, 5]))->assertNotFound();                  // there is no 5th quarter

    msgAsUser($chairman);
    $this->post(route('period-locks.request', [2026, 1]))->assertSessionHasErrors('lock');   // only the Treasurer asks
    msgAsUser(msgUser('secretary'));
    $this->post(route('period-locks.request', [2026, 1]))->assertSessionHasErrors('lock');
});

it('lets the Chairman turn a lock request down, with a reason', function () {
    [$treasurer, $chairman] = finSetup();
    finLock();

    msgAsUser($chairman);
    $this->post(route('period-locks.reject', [2026, 1]), [])->assertSessionHasErrors('note');
    $this->post(route('period-locks.reject', [2026, 1]), ['note' => 'Two receipts are still missing'])->assertSessionHasNoErrors();

    $lock = PeriodLock::withoutGlobalScopes()->first();
    expect($lock->status)->toBe('open')->and($lock->note)->toBe('Two receipts are still missing');
});

it('lets the Treasurer take a request back', function () {
    finSetup();
    finLock();
    $this->post(route('period-locks.withdraw', [2026, 1]))->assertSessionHasNoErrors();
    expect(PeriodLock::withoutGlobalScopes()->first()->status)->toBe('open');
});

it('blocks adding, editing, moving and voiding anything dated in a locked quarter', function () {
    [$treasurer, $chairman, $budget, $item] = finSetup();
    $inside = finExpense($item, '2026-02-10', 800);
    $outside = finExpense($item, '2026-05-10', 300);
    $movement = FundMovement::create(['budget_id' => $budget->id, 'type' => 'withdrawal', 'date' => '2026-02-12', 'amount' => 100, 'description' => 'Cash']);

    finLock();
    msgAsUser($chairman);
    $this->post(route('period-locks.approve', [2026, 1]));
    msgAsUser($treasurer);

    // add
    $this->post(route('transactions.store'), finPayload($item, ['date' => '2026-03-15']))->assertSessionHasErrors('date');
    // edit
    $this->put(route('transactions.update', $inside), finPayload($item, ['date' => '2026-02-10', 'amount' => 900]))->assertSessionHasErrors('date');
    // move INTO the locked quarter, and OUT of it
    $this->put(route('transactions.update', $outside), finPayload($item, ['date' => '2026-03-01']))->assertSessionHasErrors('date');
    $this->put(route('transactions.update', $inside), finPayload($item, ['date' => '2026-05-01', 'amount' => 800]))->assertSessionHasErrors('date');
    // void
    $this->post(route('transactions.void', $inside), ['void_reason' => 'Entered twice by mistake'])->assertSessionHasErrors('date');
    $this->post(route('fund-movements.void', $movement), ['void_reason' => 'Entered twice by mistake'])->assertSessionHasErrors('date');

    expect(Transaction::find($inside->id)->amount)->toEqual(800.0)
        ->and(Transaction::count())->toBe(2)
        ->and(FundMovement::count())->toBe(1);

    // other quarters are not affected
    $this->post(route('transactions.store'), finPayload($item, ['date' => '2026-05-20']))->assertSessionHasNoErrors();
    $this->post(route('transactions.void', $outside), ['void_reason' => 'Entered twice by mistake'])->assertSessionHasNoErrors();

    // and the model itself refuses, whatever page asks
    expect(fn () => finExpense($item, '2026-01-05'))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('keeps the lock after an unlock is asked for, until the Admin decides', function () {
    [$treasurer, $chairman, , $item] = finSetup();
    finLock();
    msgAsUser($chairman);
    $this->post(route('period-locks.approve', [2026, 1]));

    $this->post(route('period-locks.unlock', [2026, 1]), ['reason' => 'x'])->assertSessionHasErrors('reason');
    $this->post(route('period-locks.unlock', [2026, 1]), ['reason' => 'A receipt was found that must be recorded'])->assertSessionHasNoErrors();
    expect(PeriodLock::withoutGlobalScopes()->first()->status)->toBe('unlock_requested');

    msgAsUser($treasurer);
    $this->post(route('transactions.store'), finPayload($item, ['date' => '2026-03-15']))->assertSessionHasErrors('date');   // still locked
});

it('lets only the Admin unlock, and the Admin sees no money', function () {
    [$treasurer, $chairman, $budget, $item] = finSetup();
    finExpense($item, '2026-02-10', 4321.55);
    finLock();
    msgAsUser($chairman);
    $this->post(route('period-locks.approve', [2026, 1]));
    $this->post(route('period-locks.unlock', [2026, 1]), ['reason' => 'A receipt was found that must be recorded']);

    $lock = PeriodLock::withoutGlobalScopes()->first();

    msgAdmin();
    Auth::shouldUse('admin');
    $page = $this->get(route('admin.unlocks'))->assertOk();
    $page->assertSee('A receipt was found that must be recorded')->assertSee('Q1 2026')->assertDontSee('4,321')->assertDontSee('4321.55');
    $this->post(route('admin.unlocks.deny', $lock->id), ['note' => ''])->assertSessionHasErrors('note');
    $this->post(route('admin.unlocks.deny', $lock->id), ['note' => 'Not enough reason yet'])->assertSessionHasNoErrors();
    expect($lock->fresh()->status)->toBe('locked')->and($lock->fresh()->note)->toBe('Not enough reason yet');

    PeriodLock::withoutGlobalScopes()->whereKey($lock->id)->update(['status' => 'unlock_requested']);   // the barangay asks again
    $this->post(route('admin.unlocks.approve', $lock->id))->assertSessionHasNoErrors();
    expect($lock->fresh()->status)->toBe('open')->and($lock->fresh()->unlocked_by_admin)->not->toBeNull();

    // the barangay can change that quarter again, and must ask again to lock it
    msgAsUser($treasurer);
    $this->post(route('transactions.store'), finPayload($item, ['date' => '2026-03-15']))->assertSessionHasNoErrors();
});

it('keeps every lock inside its own barangay', function () {
    [, $chairman, , $item] = finSetup(0);
    finLock();
    msgAsUser($chairman);
    $this->post(route('period-locks.approve', [2026, 1]));

    // another barangay records an expense dated in the same quarter: not affected
    [, , , $otherItem] = finSetup(1);
    $this->post(route('transactions.store'), finPayload($otherItem, ['date' => '2026-03-15']))->assertSessionHasNoErrors();
    expect(Transaction::count())->toBe(1);
    $this->get(route('period-locks.index'))->assertOk()->assertSee('Open');
});

it('shows the Quarter lock page to everyone who can see finance, with the right buttons', function () {
    [$treasurer, $chairman] = finSetup();

    $this->get(route('period-locks.index'))->assertOk()->assertSee('Ask to lock')->assertSee('has not ended yet');
    finLock();
    $this->get(route('period-locks.index'))->assertOk()->assertSee('Take back')->assertDontSee('Approve lock');

    msgAsUser($chairman);
    $this->get(route('period-locks.index'))->assertOk()->assertSee('Approve lock')->assertSee('Waiting for the Chairman')->assertDontSee('Ask to lock');

    msgAsUser(msgUser('kagawad'));
    $this->get(route('period-locks.index'))->assertOk()->assertDontSee('Approve lock')->assertDontSee('Ask to lock');
});

// ---------------------------------------------------------------- bank reconciliation

it('works out the bank balance as it stood on a date', function () {
    [, , $budget, $item] = finSetup();
    finExpense($item, '2026-03-10', 1000);
    finExpense($item, '2026-06-10', 2000);
    FundMovement::create(['budget_id' => $budget->id, 'type' => 'receipt', 'account' => 'bank', 'date' => '2026-04-01', 'amount' => 500, 'description' => 'Share']);

    expect($budget->bankBalanceAsOf('2026-03-31'))->toEqual(49000.0)
        ->and($budget->bankBalanceAsOf('2026-04-30'))->toEqual(49500.0)
        ->and($budget->bankBalanceAsOf('2026-12-31'))->toEqual(47500.0)
        ->and($budget->bankBalanceAsOf('2026-12-31'))->toEqual($budget->fresh()->bank_balance);
});

it('records whether the bank statement agrees with the books', function () {
    [, , $budget, $item] = finSetup();
    finExpense($item, '2026-03-10', 1000);                    // the books say 49,000 on March 31

    // statement 49,300; a check of 500 has not cleared (-500) and 200 is a deposit not yet shown (+200) -> adjusted 49,000
    $this->post(route('reconciliation.store'), ['statement_date' => '2026-03-31', 'statement_balance' => 49300, 'outstanding_checks' => 500, 'deposits_in_transit' => 200])
        ->assertSessionHasNoErrors()->assertSessionHas('success');
    $matched = BankReconciliation::firstOrFail();
    expect($matched->matches())->toBeTrue()->and((float) $matched->system_balance)->toEqual(49000.0)->and($matched->adjustedBalance())->toEqual(49000.0);

    // a statement that disagrees by 150
    $this->post(route('reconciliation.store'), ['statement_date' => '2026-03-31', 'statement_balance' => 49150])->assertSessionHas('warning');
    $off = BankReconciliation::orderByDesc('id')->first();
    expect($off->matches())->toBeFalse()->and((float) $off->difference)->toEqual(150.0);

    $this->get(route('reconciliation.index'))->assertOk()->assertSee('Matched')->assertSee('Difference');
});

it('checks the reconciliation form, and lets only the Treasurer save one', function () {
    [$treasurer, $chairman] = finSetup();

    $this->post(route('reconciliation.store'), [])->assertSessionHasErrors(['statement_date', 'statement_balance']);
    $this->post(route('reconciliation.store'), ['statement_date' => '2027-01-01', 'statement_balance' => 10])->assertSessionHasErrors('statement_date');   // not in the future
    $this->post(route('reconciliation.store'), ['statement_date' => '2026-03-31', 'statement_balance' => -5])->assertSessionHasErrors('statement_balance');

    msgAsUser($chairman);
    $this->post(route('reconciliation.store'), ['statement_date' => '2026-03-31', 'statement_balance' => 100])->assertForbidden();
    $this->get(route('reconciliation.index'))->assertOk();            // but can read the history
    expect(BankReconciliation::count())->toBe(0);
});

it('has no way to change or delete a saved reconciliation', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'reconciliation'));
    expect($routes->map(fn ($r) => implode('|', array_diff($r->methods(), ['HEAD'])))->sort()->values()->all())->toBe(['GET', 'POST']);
});

it('leaves a voided expense out of the ledger, journal, dashboard, budget page and reports', function () {
    [, , $budget, $item] = finSetup();
    $keep = Transaction::create(['date' => '2026-03-10', 'description' => 'KEEP-ME-VISIBLE', 'category_id' => $item->category_id, 'subcategory_id' => $item->id, 'amount' => 1000, 'payment_method' => 'bank']);
    $gone = Transaction::create(['date' => '2026-03-11', 'description' => 'VOIDED-DESCRIPTION-XYZ', 'category_id' => $item->category_id, 'subcategory_id' => $item->id, 'amount' => 700, 'payment_method' => 'bank']);
    $this->post(route('transactions.void', $gone), ['void_reason' => 'Wrong amount typed in'])->assertSessionHasNoErrors();

    foreach (['dashboard', 'budget.index', 'ledger.index', 'journal.index', 'reports.index', 'transactions.index'] as $route) {
        $this->withSession(['budget_id' => $budget->id])->get(route($route))->assertOk()->assertDontSee('VOIDED-DESCRIPTION-XYZ');
    }
    $this->withSession(['budget_id' => $budget->id])->get(route('transactions.index'))->assertSee('KEEP-ME-VISIBLE');
    $this->withSession(['budget_id' => $budget->id])->get(route('transactions.index', ['voided' => 1]))->assertSee('VOIDED-DESCRIPTION-XYZ');
});
