<?php

namespace App\Http\Controllers;

use App\Http\Requests\FundMovementRequest;
use App\Models\Budget;
use App\Models\FundMovement;
use App\Support\FundBalances;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FundMovementController extends Controller
{
    private const PER_PAGE = 15;

    /** Columns the list can be sorted by (the keys the table headers send). */
    private const SORTS = ['date', 'type', 'account', 'reference', 'amount'];

    /* ------------------------------------------------------------
     | List (current budget) with filters and totals
     * ------------------------------------------------------------ */

    public function index(Request $request): View
    {
        $budget = Budget::current();

        if (! $budget) {
            return view('fund-movements.index', ['budget' => null]);
        }

        $filters = $this->filters($request);
        $sort    = $filters['sort'] ?? 'date';
        $dir     = $filters['dir'] ?? 'desc';
        $query   = FundMovement::query()->filter($filters + ['budget_id' => $budget->id]);

        // "Show voided" lists the voided movements too (struck through); balances and the printed report never include them
        $listQuery = $request->boolean('voided') ? (clone $query)->withoutGlobalScope('notVoided') : clone $query;
        $movements = $this->sorted($listQuery, $sort, $dir)
            ->paginate($request->boolean('print') ? 100000 : self::PER_PAGE) // ?print=1 prints every row, not just one page
            ->withQueryString();

        return view('fund-movements.index', [
            'budget'    => $budget,
            'filters'   => $filters,
            'sort'      => $sort,
            'dir'       => $dir,
            'movements' => $movements,
            // The printed report always lists every movement that matches the filters, not just the page on screen
            'printRows' => Gate::allows('print') ? $this->sorted(clone $query, $sort, $dir)->get() : collect(),
            'totals'    => $this->totals($query),
            'balances'  => $this->balances($budget),
        ]);
    }

    /* ------------------------------------------------------------
     | Record a movement
     * ------------------------------------------------------------ */

    public function create(): View|RedirectResponse
    {
        $budget = Budget::current();

        if (! $budget) {
            return redirect()->route('budget.index')
                ->withErrors(['budget' => 'Create a budget first; fund movements are recorded against it.']);
        }

        return view('fund-movements.create', $this->formData($budget) + ['movement' => null]);
    }

    public function store(FundMovementRequest $request): RedirectResponse
    {
        $budget   = $request->budget();
        $movement = $budget->fundMovements()->create($request->movementData());

        return redirect()->route('fund-movements.index')->with('success', $this->summary($movement) . ' recorded.');
    }

    /* ------------------------------------------------------------
     | Edit / delete (the balances are re-checked, see FundBalances)
     * ------------------------------------------------------------ */

    public function edit(FundMovement $fundMovement): View
    {
        $budget = $fundMovement->budget;

        // Show the budget this movement belongs to in the top-bar switcher
        session(['budget_id' => $budget->id]);

        return view('fund-movements.edit', $this->formData($budget, $fundMovement) + ['movement' => $fundMovement]);
    }

    public function update(FundMovementRequest $request, FundMovement $fundMovement): RedirectResponse
    {
        $fundMovement->update($request->movementData());

        session(['budget_id' => $fundMovement->budget_id]);

        return redirect()->route('fund-movements.index')->with('success', 'Fund movement updated.');
    }

    /** Movements are never deleted: voiding one takes its effect out of the balances, so the same safety rule applies. */
    public function void(Request $request, FundMovement $fundMovement): RedirectResponse
    {
        $data = $request->validate(
            ['void_reason' => ['required', 'string', 'min:10', 'max:255']],
            ['void_reason.required' => 'Write why this movement is voided.', 'void_reason.min' => 'Write a reason of at least 10 letters, so the books explain why.'],
        );

        $budget = $fundMovement->budget;

        // Balances as they would be without this movement; expenses may already depend on that money
        $before   = FundBalances::without($budget);
        $after    = FundBalances::without($budget, $fundMovement);
        $problems = FundBalances::problems($before, $after, null, 'Voiding this movement');

        if ($problems) {
            return back()->withErrors(['fund_movement' => $problems]);
        }

        $fundMovement->voidWith(trim($data['void_reason']), (int) auth()->id());

        return back()->with('success', 'Fund movement voided. It stays in the books, marked as voided, and no longer counts in any balance.');
    }

    /* ------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------ */

    /** Everything the create/edit form needs. */
    private function formData(Budget $budget, ?FundMovement $movement = null): array
    {
        return [
            'budget'      => $budget,
            'balances'    => $this->balances($budget),
            // What the movement being edited already did, so the form can take it back out of the balances
            'origin'      => [
                'bank' => $movement ? $movement->bankEffect() : 0.0,
                'cash' => $movement ? $movement->cashEffect() : 0.0,
            ],
            'defaultDate' => now()->year === (int) $budget->year ? now()->toDateString() : "{$budget->year}-01-01",
        ];
    }

    private function balances(Budget $budget): array
    {
        return [
            'bank'    => $budget->bank_balance,
            'cash'    => $budget->cash_balance,
            'total'   => $budget->total_funds_on_hand,
            'unspent' => $budget->remaining_budget, // appropriation not yet spent (a withdrawal does not change it)
        ];
    }

    /** One sentence describing what was recorded, for the success message. */
    private function summary(FundMovement $movement): string
    {
        $amount = '₱' . number_format((float) $movement->amount, 2);

        return match ($movement->type) {
            'receipt'    => $movement->account === 'bank'
                ? "{$amount} received into the bank"
                : "{$amount} received into cash on hand",
            'withdrawal' => "{$amount} withdrawn from the bank into cash on hand",
            default      => "{$amount} deposited from cash on hand into the bank",
        };
    }

    /* ------------------------------------------------------------
     | Filters and totals for the list page
     * ------------------------------------------------------------ */

    private function filters(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to'   => array_merge(['nullable', 'date'], $request->filled('date_from') ? ['after_or_equal:date_from'] : []),
            'type'      => ['nullable', Rule::in(array_keys(FundMovement::TYPES))],
            'account'   => ['nullable', Rule::in(array_keys(FundMovement::ACCOUNTS))],
            'search'    => ['nullable', 'string', 'max:100'],
            'sort'      => ['nullable', Rule::in(self::SORTS)],
            'dir'       => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
    }

    /** Sort the list by one of the columns the table headers offer; the id keeps equal rows in a stable order. */
    private function sorted(Builder $query, string $sort, string $dir): Builder
    {
        $column = match ($sort) {
            'type'      => 'type',
            'account'   => 'account',
            'reference' => 'reference',
            'amount'    => 'amount',
            default     => 'date',
        };

        return $query->orderBy($column, $dir)->orderBy('id', $dir);
    }

    /** Count and amounts of the filtered rows (all pages, not just the visible one). */
    private function totals(Builder $query): object
    {
        return (clone $query)->toBase()->selectRaw(
            'COUNT(*) AS rows_count, '
            . "COALESCE(SUM(CASE WHEN type = 'receipt' THEN amount ELSE 0 END), 0) AS received, "
            . "COALESCE(SUM(CASE WHEN type = 'withdrawal' THEN amount ELSE 0 END), 0) AS withdrawn, "
            . "COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) AS deposited"
        )->first();
    }
}
