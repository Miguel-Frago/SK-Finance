<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Support\CategoryPaths;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LedgerController extends Controller
{
    private const PER_PAGE         = 20;
    private const SUMMARY_PER_PAGE = 25;

    /* ------------------------------------------------------------
     | Ledger of the current budget (read-only)
     |
     |   nothing picked      -> summary of all items
     |   category picked     -> summary of its items, nested categories included
     |   item picked         -> the item's ledger with a running balance
     |
     | Expenses are recorded in Transactions; nothing is created or edited here.
     * ------------------------------------------------------------ */

    public function index(Request $request): View|RedirectResponse
    {
        $budget = Budget::current();

        if (! $budget) {
            return view('ledger.index', ['budget' => null]);
        }

        $filters = $this->filters($request);
        $paths   = CategoryPaths::forBudget($budget);
        $items   = $this->items($paths); // only this budget's items

        $data = [
            'budget'          => $budget,
            'filters'         => $filters,
            'paths'           => $paths,
            'itemsByCategory' => $items->groupBy('category_id'),
            'item'            => null,
            'category'        => null,
        ];

        // An item wins when both an item and a category are in the address
        if (! empty($filters['subcategory_id'])) {
            $item = $items->firstWhere('id', (int) $filters['subcategory_id']);

            if (! $item) {
                return $this->notInBudget();
            }

            return view('ledger.index', array_merge($data, $this->itemLedger($request, $item, $filters)));
        }

        $scoped   = $items;
        $category = null;

        if (! empty($filters['category_id'])) {
            $category = $budget->categories()->find($filters['category_id']);

            if (! $category) {
                return $this->notInBudget();
            }

            // Picking a category includes everything nested inside it
            $ids    = array_map('intval', $category->treeIds());
            $scoped = $items->filter(fn (Subcategory $item) => in_array((int) $item->category_id, $ids, true))->values();
        }

        return view('ledger.index', array_merge($data, [
            'category'    => $category,
            'summary'     => $this->summary($scoped),
            // The printed report always lists every item, not just the page on screen
            'printItems'  => Gate::allows('print') ? $scoped : collect(),
            'summaryRows' => $this->paginate($scoped, $request, self::SUMMARY_PER_PAGE),
        ]));
    }

    /* ------------------------------------------------------------
     | Item ledger
     * ------------------------------------------------------------ */

    /**
     * Running balances come from Subcategory::ledgerEntries(), which works them out over
     * ALL of the item's expenses. Filters and paging are applied afterwards, so a filtered
     * or paged row still shows the true balance after that expense.
     */
    private function itemLedger(Request $request, Subcategory $item, array $filters): array
    {
        $all  = $item->ledgerEntries();
        $rows = $this->apply($all, $filters);

        return [
            'item'       => $item,
            // The printed report always lists every matching expense, not just the page on screen
            'printRows'  => Gate::allows('print') ? $rows : collect(),
            'rows'       => $this->paginate($rows, $request, self::PER_PAGE),
            'entryCount' => $all->count(),
            'totals'     => [
                'rows'  => $rows->count(),
                'spent' => round($rows->sum(fn (Transaction $entry) => (float) $entry->amount), 2),
            ],
        ];
    }

    private function apply(Collection $entries, array $filters): Collection
    {
        return $entries
            ->when($filters['date_from'] ?? null, fn ($c, $date) => $c->filter(fn (Transaction $entry) => $entry->date->toDateString() >= $date))
            ->when($filters['date_to'] ?? null, fn ($c, $date) => $c->filter(fn (Transaction $entry) => $entry->date->toDateString() <= $date))
            ->when($filters['payment_method'] ?? null, fn ($c, $method) => $c->filter(fn (Transaction $entry) => $entry->payment_method === $method))
            ->when($filters['search'] ?? null, function ($c, $term) {
                return $c->filter(function (Transaction $entry) use ($term) {
                    $haystack = implode(' ', [$entry->description, $entry->payee, $entry->voucher_number, $entry->check_number]);

                    return mb_stripos($haystack, $term) !== false;
                });
            })
            ->values();
    }

    /* ------------------------------------------------------------
     | Summary of items
     * ------------------------------------------------------------ */

    /** Every item of the budget with its total spent, in category-tree order. */
    private function items(array $paths): Collection
    {
        $order = array_flip(array_keys($paths)); // category id => position in the tree

        return Subcategory::query()
            ->whereIn('category_id', array_keys($paths))
            ->withSum('transactions', 'amount')
            ->orderBy('id')
            ->get()
            ->sort(fn (Subcategory $a, Subcategory $b) => [$order[$a->category_id] ?? PHP_INT_MAX, $a->id] <=> [$order[$b->category_id] ?? PHP_INT_MAX, $b->id])
            ->values();
    }

    /** Totals over all the given items (every page, not just the visible one). */
    private function summary(Collection $items): array
    {
        $allocated = round($items->sum(fn (Subcategory $item) => (float) $item->allocated_amount), 2);
        $spent     = round($items->sum(fn (Subcategory $item) => $item->spent), 2);

        return [
            'items'     => $items->count(),
            'allocated' => $allocated,
            'spent'     => $spent,
            'balance'   => round($allocated - $spent, 2),
            'percent'   => $allocated > 0 ? round(($spent / $allocated) * 100, 1) : 0.0,
            'overspent' => $items->filter(fn (Subcategory $item) => $item->is_overspent)->count(),
        ];
    }

    /* ------------------------------------------------------------
     | Filters and paging
     * ------------------------------------------------------------ */

    private function filters(Request $request): array
    {
        $filters = $request->validate([
            'subcategory_id' => ['nullable', 'integer'],
            'category_id'    => ['nullable', 'integer'],
            'date_from'      => ['nullable', 'date'],
            'date_to'        => array_merge(['nullable', 'date'], $request->filled('date_from') ? ['after_or_equal:date_from'] : []),
            'payment_method' => ['nullable', Rule::in(array_keys(Transaction::PAYMENT_METHODS))],
            'search'         => ['nullable', 'string', 'max:100'],
        ]);

        // Dates are compared as Y-m-d text
        foreach (['date_from', 'date_to'] as $key) {
            if (! empty($filters[$key])) {
                $filters[$key] = Carbon::parse($filters[$key])->toDateString();
            }
        }

        return $filters;
    }

    private function paginate(Collection $rows, Request $request, int $perPage): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();

        if ($request->boolean('print')) {
            $perPage = max($rows->count(), 1); // ?print=1 prints every row, not just one page
        }

        return (new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url()]
        ))->withQueryString();
    }

    private function notInBudget(): RedirectResponse
    {
        return redirect()->route('ledger.index')
            ->withErrors(['ledger' => 'That item or category is not part of the current budget.']);
    }
}
