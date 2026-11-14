<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Support\PrintReport;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /* ------------------------------------------------------------
     | Summary cards and charts for the current budget
     * ------------------------------------------------------------ */

    public function index(): View
    {
        $budget = Budget::current();

        if (! $budget) {
            return view('dashboard.index', ['budget' => null]);
        }

        $spent = $budget->total_spent;
        $total = (float) $budget->total_budget;

        return view('dashboard.index', [
            'budget'          => $budget,
            'spent'           => $spent,
            'left'            => $budget->remaining_budget,
            'spentPct'        => $total > 0 ? min(100, round($spent / $total * 100, 1)) : 0,
            'bankSpent'       => $budget->bank_spent,
            'cashSpent'       => $budget->cash_spent,
            'categories'      => $this->categorySegments($budget),
            'months'          => $this->monthlySpending($budget),
            'nearlyUsed'      => $this->nearlyUsedItems($budget),
            'printCategories' => PrintReport::budgetRows($budget->categoryTree(), false), // for the printed report
            'recentExpenses'  => Transaction::query()->filter(['budget_id' => $budget->id])
                ->with('subcategory')->orderByDesc('date')->orderByDesc('id')->limit(5)->get(),
            'recentMovements' => $budget->fundMovements()->orderByDesc('date')->orderByDesc('id')->limit(5)->get(),
        ]);
    }

    /* ------------------------------------------------------------
     | Chart data
     * ------------------------------------------------------------ */

    /** One donut slice per top-level category (money spent), plus the budget still left. */
    private function categorySegments(Budget $budget): array
    {
        $segments = [];

        foreach ($budget->categoryTree() as $index => $root) {
            $segments[] = ['label' => $root->name, 'value' => $root->tree_spent, 'color' => \App\Support\Donut::color($index)];
        }

        if ($budget->remaining_budget > 0) {
            $segments[] = ['label' => 'Budget left', 'value' => $budget->remaining_budget, 'color' => \App\Support\Donut::LEFT_COLOR];
        }

        return $segments;
    }

    /**
     * Twelve rows (January to December) with the amount spent that month.
     * Each row: ['label' => 'Jan', 'name' => 'January', 'total' => float, 'pct' => bar height 0-100
     * (relative to the busiest month), 'level' => low/medium/high colour for that height]
     */
    private function monthlySpending(Budget $budget): array
    {
        $totals = Transaction::query()
            ->filter(['budget_id' => $budget->id])
            ->toBase()
            ->selectRaw("CAST(strftime('%m', date) AS INTEGER) AS month, SUM(amount) AS total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $max  = (float) ($totals->max() ?? 0);
        $rows = [];

        foreach (self::MONTHS as $index => $label) {
            $value  = round((float) ($totals[$index + 1] ?? 0), 2);
            $pct    = $max > 0 ? round($value / $max * 100, 1) : 0;
            $rows[] = [
                'label' => $label,
                'name'  => date('F', mktime(0, 0, 0, $index + 1, 1)),
                'total' => $value,
                'pct'   => $pct,
                'level' => \App\Support\Usage::level($pct),
            ];
        }

        return $rows;
    }

    /** Items that have used 80% or more of their allocation, most used first (at most 5). */
    private function nearlyUsedItems(Budget $budget): Collection
    {
        return Subcategory::query()
            ->whereIn('category_id', $budget->categories()->pluck('id'))
            ->where('allocated_amount', '>', 0)
            ->withSum('transactions', 'amount')
            ->get()
            ->filter(fn (Subcategory $item) => $item->utilization_percent >= 80)
            ->sortByDesc('utilization_percent')
            ->take(5)
            ->values();
    }
}
