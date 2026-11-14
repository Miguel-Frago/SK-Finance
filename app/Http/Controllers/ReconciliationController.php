<?php

namespace App\Http\Controllers;

use App\Models\BankReconciliation;
use App\Models\Budget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Bank reconciliation: the Treasurer compares the bank statement with the books. Everyone who can see finance can read the history. */
class ReconciliationController extends Controller
{
    public function index(): View
    {
        $budget = Budget::current();

        return view('reconciliation.index', [
            'budget'  => $budget,
            'history' => $budget ? BankReconciliation::query()->where('budget_id', $budget->id)->with('preparedBy')->orderByDesc('statement_date')->orderByDesc('id')->get() : collect(),
            'today'   => $budget ? $budget->bankBalanceAsOf(today()) : 0,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $budget = Budget::current();
        abort_unless($budget, 404);

        $data = $request->validate([
            'statement_date'      => ['required', 'date', 'before_or_equal:today'],
            'statement_balance'   => ['required', 'numeric', 'min:0', 'max:999999999.99'],
            'outstanding_checks'  => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'deposits_in_transit' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'notes'               => ['nullable', 'string', 'max:500'],
        ]);

        $system = $budget->bankBalanceAsOf($data['statement_date']);
        $adjusted = round((float) $data['statement_balance'] - (float) ($data['outstanding_checks'] ?? 0) + (float) ($data['deposits_in_transit'] ?? 0), 2);

        $row = new BankReconciliation($data + ['budget_id' => $budget->id]);
        $row->outstanding_checks = $data['outstanding_checks'] ?? 0;
        $row->deposits_in_transit = $data['deposits_in_transit'] ?? 0;
        $row->forceFill(['system_balance' => $system, 'difference' => round($adjusted - $system, 2), 'prepared_by' => auth()->id()])->save();

        return redirect()->route('reconciliation.index')->with(
            $row->matches() ? 'success' : 'warning',
            $row->matches() ? 'Reconciled: the bank statement agrees with the books.' : 'Saved. The bank statement does NOT agree with the books: see the difference below.'
        );
    }
}
