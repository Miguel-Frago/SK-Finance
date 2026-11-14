<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Support\JournalBook;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class JournalController extends Controller
{
    private const PER_PAGE = 20;

    /* ------------------------------------------------------------
     | Journal of the current budget (read-only)
     * ------------------------------------------------------------ */

    public function index(Request $request): View
    {
        $budget = Budget::current();

        if (! $budget) {
            return view('journal.index', ['budget' => null]);
        }

        $filters = $this->filters($request);

        // Balances are worked out over every entry first; only then do filters and paging apply
        $entries = $this->apply(JournalBook::entries($budget), $filters);

        if (($filters['order'] ?? 'asc') === 'desc') {
            $entries = $entries->reverse()->values();
        }

        return view('journal.index', [
            'budget'  => $budget,
            'filters' => $filters,
            // The printed report always lists every entry that matches the filters, not just the page on screen
            'printEntries' => Gate::allows('print') ? $entries : collect(),
            'entries' => $this->paginate($entries, $request),
            'totals'  => $this->totals($entries),
        ]);
    }

    /* ------------------------------------------------------------
     | Filters, totals and paging
     * ------------------------------------------------------------ */

    private function filters(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to'   => array_merge(['nullable', 'date'], $request->filled('date_from') ? ['after_or_equal:date_from'] : []),
            'source'    => ['nullable', Rule::in(array_keys(JournalBook::SOURCES))],
            'account'   => ['nullable', Rule::in(['bank', 'cash'])],
            'search'    => ['nullable', 'string', 'max:100'],
            'order'     => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
    }

    private function apply(Collection $entries, array $filters): Collection
    {
        return $entries
            ->when($filters['date_from'] ?? null, fn ($c, $date) => $c->filter(fn ($row) => $row['date'] >= $date))
            ->when($filters['date_to'] ?? null, fn ($c, $date) => $c->filter(fn ($row) => $row['date'] <= $date))
            ->when($filters['source'] ?? null, fn ($c, $source) => $c->filter(fn ($row) => $row['source'] === $source))
            // "Bank" or "Cash" keeps the entries that move that account
            ->when($filters['account'] ?? null, fn ($c, $account) => $c->filter(fn ($row) => $row[$account] != 0))
            ->when($filters['search'] ?? null, function ($c, $term) {
                return $c->filter(function ($row) use ($term) {
                    $haystack = ($row['reference'] ?? '') . ' ' . $row['particulars'] . ' ' . $row['detail'];

                    return mb_stripos($haystack, $term) !== false;
                });
            })
            ->values();
    }

    /** Debits (money in) and credits (money out) of the filtered rows, per account. */
    private function totals(Collection $entries): array
    {
        return [
            'rows'     => $entries->count(),
            'bank_in'  => round($entries->sum(fn ($row) => max($row['bank'], 0)), 2),
            'bank_out' => round($entries->sum(fn ($row) => max(-$row['bank'], 0)), 2),
            'cash_in'  => round($entries->sum(fn ($row) => max($row['cash'], 0)), 2),
            'cash_out' => round($entries->sum(fn ($row) => max(-$row['cash'], 0)), 2),
        ];
    }

    private function paginate(Collection $entries, Request $request): LengthAwarePaginator
    {
        $page    = LengthAwarePaginator::resolveCurrentPage();
        $perPage = $request->boolean('print') ? max($entries->count(), 1) : self::PER_PAGE; // ?print=1 prints every row

        return (new LengthAwarePaginator(
            $entries->forPage($page, $perPage)->values(),
            $entries->count(),
            $perPage,
            $page,
            ['path' => $request->url()]
        ))->withQueryString();
    }
}
