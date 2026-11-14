<?php

namespace App\Support;

/**
 * Geometry for the SVG donut charts on the budget page.
 *
 * The donut is a circle with a circumference of exactly 100 units
 * (r = 15.9155), so every segment's length is simply its percentage.
 */
class Donut
{
    /**
     * Category colours. Blue, green, black, red and orange/yellow are kept out of this list on
     * purpose: they mean total budget, allocated, unallocated, spent and budget left everywhere
     * else in the system, so a category must never look like one of them.
     */
    public const PALETTE = [
        '#4f46e5', '#0d9488', '#7c3aed', '#0891b2', '#db2777',
        '#65a30d', '#0284c7', '#c026d3', '#64748b',
    ];

    /** Colour of the "unallocated" slice (black). */
    public const UNALLOCATED_COLOR = '#111827';

    /** Colour of the "budget left" slice (orange). */
    public const LEFT_COLOR = '#f97316';

    public static function color(int $index): string
    {
        return self::PALETTE[$index % count(self::PALETTE)];
    }

    /**
     * @param  array<int, array{label: string, value: float|int|string, color: string}>  $segments
     * @return array{total: float, arcs: array<int, array<string, mixed>>}
     */
    public static function arcs(array $segments): array
    {
        $segments = array_values(array_filter($segments, fn (array $segment) => (float) $segment['value'] > 0));
        $total    = array_sum(array_map(fn (array $segment) => (float) $segment['value'], $segments));
        $gap      = count($segments) > 1 ? 0.6 : 0.0; // small white gap between slices

        $arcs   = [];
        $cursor = 0.0;

        foreach ($segments as $segment) {
            $share = ((float) $segment['value'] / $total) * 100;
            $dash  = max($share - $gap, 0.05);

            $arcs[] = $segment + [
                'share'  => round($share, 1),
                'dash'   => round($dash, 3),
                'rest'   => round(100 - $dash, 3),
                // 25 moves the starting point from 3 o'clock to 12 o'clock
                'offset' => round(25 - $cursor, 3),
            ];

            $cursor += $share;
        }

        return ['total' => round($total, 2), 'arcs' => $arcs];
    }
}
