<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Small helpers that prepare data for the printed reports (the "print" section of each page).
 * Nothing here touches the screen layout.
 */
class PrintReport
{
    /** 1,234.50 (no peso sign: the table headings say "₱" so the columns stay narrow on paper). */
    public static function num(float|int|string|null $value): string
    {
        return number_format((float) $value, 2);
    }

    public static function peso(float|int|string|null $value): string
    {
        $value = (float) $value;

        return ($value < 0 ? '-' : '') . '₱' . number_format(abs($value), 2);
    }

    /** Compact amount for chart labels: 1.2K, 3.4M. */
    public static function short(float|int|string|null $value): string
    {
        $value = (float) $value;
        $abs   = abs($value);

        return match (true) {
            $abs >= 1_000_000 => round($value / 1_000_000, 1) . 'M',
            $abs >= 1_000     => round($value / 1_000, 1) . 'K',
            default           => (string) round($value),
        };
    }

    public static function percent(float|int|string|null $part, float|int|string|null $whole): float
    {
        $whole = (float) $whole;

        return $whole > 0 ? round(((float) $part / $whole) * 100, 1) : 0.0;
    }

    /**
     * The budget's category tree as one flat list, in reading order, for a table with indentation.
     * Each row: type (category|item), depth, code, name, allocated, spent, balance, percent, level, over.
     *
     * @param  Collection  $tree  Budget::categoryTree()
     */
    public static function budgetRows(Collection $tree, bool $withItems = true): array
    {
        $rows = [];

        $walk = function (Collection $categories, int $depth) use (&$walk, &$rows, $withItems) {
            foreach ($categories as $category) {
                $allocated = (float) $category->tree_allocated;
                $spent     = (float) $category->tree_spent;
                $pct       = self::percent($spent, $allocated);

                $rows[] = [
                    'type'      => 'category',
                    'depth'     => $depth,
                    'code'      => $category->code ?? null,
                    'name'      => $category->name,
                    'allocated' => $allocated,
                    'spent'     => $spent,
                    'balance'   => round($allocated - $spent, 2),
                    'percent'   => $pct,
                    'level'     => Usage::level($pct),
                    'over'      => $spent > $allocated && $allocated >= 0 && $spent > 0,
                ];

                if ($withItems) {
                    foreach ($category->subcategories as $item) {
                        $rows[] = [
                            'type'      => 'item',
                            'depth'     => $depth + 1,
                            'code'      => $item->code,
                            'name'      => $item->name,
                            'allocated' => (float) $item->allocated_amount,
                            'spent'     => (float) $item->spent,
                            'balance'   => (float) $item->balance,
                            'percent'   => (float) $item->utilization_percent,
                            'level'     => Usage::level($item->utilization_percent),
                            'over'      => (bool) $item->is_overspent,
                        ];
                    }
                }

                $walk($category->children ?? collect(), $depth + 1);
            }
        };

        $walk($tree, 0);

        return $rows;
    }

    /**
     * Twelve monthly totals from any list of rows that have a date and an amount.
     *
     * @return array<int, array{label: string, name: string, total: float, pct: float}>
     */
    public static function monthly(Collection $rows, string $dateKey = 'date', string $amountKey = 'amount'): array
    {
        $totals = array_fill(1, 12, 0.0);

        foreach ($rows as $row) {
            $date = is_array($row) ? $row[$dateKey] : $row->{$dateKey};
            $amt  = is_array($row) ? $row[$amountKey] : $row->{$amountKey};
            $month = (int) \Illuminate\Support\Carbon::parse($date)->format('n');

            $totals[$month] += (float) $amt;
        }

        $max  = max($totals) ?: 0;
        $out  = [];

        foreach ($totals as $month => $total) {
            $out[] = [
                'label' => date('M', mktime(0, 0, 0, $month, 1)),
                'name'  => date('F', mktime(0, 0, 0, $month, 1)),
                'total' => round($total, 2),
                'pct'   => $max > 0 ? round($total / $max * 100, 1) : 0.0,
            ];
        }

        return $out;
    }

    /** "Filters applied" for the heading of a report: [['label' => 'Paid from', 'value' => 'Bank'], ...]. */
    public static function filterLines(array $pairs): array
    {
        return collect($pairs)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value, $label) => ['label' => $label, 'value' => (string) $value])
            ->values()
            ->all();
    }
}
