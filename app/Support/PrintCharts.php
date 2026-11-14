<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Charts for the printed reports. Everything is plain HTML/CSS bars or inline SVG: no JavaScript,
 * no external libraries, and every size is a percentage, so a chart fits Letter and A4 alike.
 * All text that comes from the database is escaped here.
 */
class PrintCharts
{
    /** A donut with its legend beside it. $segments: [['label', 'value', 'color'], ...] */
    public static function donut(array $segments, string $centerTop, string $centerBottom = ''): HtmlString
    {
        $chart = Donut::arcs($segments);

        $svg = '<svg class="pr-donut-svg" viewBox="0 0 42 42" role="img">'
            . '<circle cx="21" cy="21" r="15.9155" fill="none" stroke="#e2e8f0" stroke-width="5"/>';

        foreach ($chart['arcs'] as $arc) {
            $svg .= '<circle cx="21" cy="21" r="15.9155" fill="none" stroke="' . e($arc['color']) . '" stroke-width="5"'
                . ' stroke-dasharray="' . $arc['dash'] . ' ' . $arc['rest'] . '" stroke-dashoffset="' . $arc['offset'] . '"/>';
        }

        $svg .= '<text x="21" y="21.6" text-anchor="middle" class="pr-donut-top">' . e($centerTop) . '</text>';

        if ($centerBottom !== '') {
            $svg .= '<text x="21" y="26.4" text-anchor="middle" class="pr-donut-bottom">' . e($centerBottom) . '</text>';
        }

        $svg .= '</svg>';

        $legend = '<ul class="pr-legend">';

        foreach ($chart['arcs'] as $arc) {
            $legend .= '<li><i style="background:' . e($arc['color']) . '"></i>'
                . '<span class="pr-legend-name">' . e($arc['label']) . '</span>'
                . '<span class="pr-legend-val">' . e(PrintReport::peso($arc['value'])) . '</span>'
                . '<span class="pr-legend-pct">' . $arc['share'] . '%</span></li>';
        }

        if (! $chart['arcs']) {
            $legend .= '<li class="pr-empty">Nothing to show yet.</li>';
        }

        return new HtmlString('<div class="pr-donut">' . $svg . $legend . '</ul></div>');
    }

    /**
     * Horizontal bars, one per row. $rows: [['label', 'value', 'display'?, 'color'?, 'note'?], ...]
     * Bars are drawn against $max (the biggest value when not given).
     */
    public static function bars(array $rows, ?float $max = null, string $defaultColor = '#3a68c8'): HtmlString
    {
        $max ??= max(array_map(fn ($row) => abs((float) $row['value']), $rows) ?: [0]);

        $html = '<div class="pr-bars">';

        foreach ($rows as $row) {
            $value = (float) $row['value'];
            $width = $max > 0 ? min(100, round(abs($value) / $max * 100, 1)) : 0;
            $color = $row['color'] ?? $defaultColor;
            $show  = $row['display'] ?? PrintReport::peso($value);

            $html .= '<div class="pr-bar-row">'
                . '<span class="pr-bar-label">' . e($row['label']) . (! empty($row['note']) ? '<small>' . e($row['note']) . '</small>' : '') . '</span>'
                . '<span class="pr-bar-track"><span class="pr-bar-fill" style="width:' . $width . '%;background:' . e($color) . '"></span></span>'
                . '<span class="pr-bar-val">' . e($show) . '</span>'
                . '</div>';
        }

        if (! $rows) {
            $html .= '<p class="pr-empty">Nothing to show yet.</p>';
        }

        return new HtmlString($html . '</div>');
    }

    /** A single stacked bar split in parts, with a legend. $parts: [['label', 'value', 'color'], ...] */
    public static function split(array $parts): HtmlString
    {
        $total = array_sum(array_map(fn ($part) => max((float) $part['value'], 0), $parts));

        $bar    = '<div class="pr-split">';
        $legend = '<div class="pr-split-legend">';

        foreach ($parts as $part) {
            $value = max((float) $part['value'], 0);
            $pct   = $total > 0 ? round($value / $total * 100, 1) : 0;

            if ($pct > 0) {
                $bar .= '<span style="width:' . $pct . '%;background:' . e($part['color']) . '">' . ($pct >= 12 ? $pct . '%' : '') . '</span>';
            }

            $legend .= '<span><i style="background:' . e($part['color']) . '"></i>' . e($part['label']) . ' <b>' . e(PrintReport::peso($value)) . '</b> (' . $pct . '%)</span>';
        }

        if ($total <= 0) {
            $bar .= '<span class="pr-split-empty">No amounts yet</span>';
        }

        return new HtmlString($bar . '</div>' . $legend . '</div>');
    }

    /** Twelve upright columns (Jan to Dec) from PrintReport::monthly(). */
    public static function columns(array $months, string $color = '#3a68c8'): HtmlString
    {
        $html = '<div class="pr-cols">';

        foreach ($months as $month) {
            $height = $month['total'] > 0 ? max($month['pct'], 3) : 0;

            $html .= '<div class="pr-col">'
                . '<span class="pr-col-val">' . ($month['total'] > 0 ? e(PrintReport::short($month['total'])) : '') . '</span>'
                . '<span class="pr-col-track"><span class="pr-col-bar" style="height:' . $height . '%;background:' . e($color) . '"></span></span>'
                . '<span class="pr-col-label">' . e($month['label']) . '</span>'
                . '</div>';
        }

        return new HtmlString($html . '</div>');
    }

    /**
     * Line chart of one or more series that share the same points.
     * $series: [['label', 'color', 'values' => [..]], ...]; $labels: text for the first and last point.
     */
    public static function lines(array $series, array $labels = []): HtmlString
    {
        $w = 600;
        $h = 150;
        $padL = 46;
        $padR = 8;
        $padT = 10;
        $padB = 18;

        $all = [];
        foreach ($series as $one) {
            foreach ($one['values'] as $value) {
                $all[] = (float) $value;
            }
        }

        if (count($all) < 2) {
            return new HtmlString('<p class="pr-empty">Not enough entries to draw a trend.</p>');
        }

        $min   = min(0, min($all));
        $max   = max($all);
        $range = ($max - $min) ?: 1;
        $count = count($series[0]['values']);
        $plotW = $w - $padL - $padR;
        $plotH = $h - $padT - $padB;

        $x = fn (int $i) => $padL + ($count > 1 ? $i / ($count - 1) : 0) * $plotW;
        $y = fn (float $v) => $padT + (1 - (($v - $min) / $range)) * $plotH;

        $svg = '<svg class="pr-lines" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">';

        foreach ([0, .5, 1] as $step) {
            $value = $min + $range * $step;
            $yy    = round($y($value), 1);

            $svg .= '<line x1="' . $padL . '" x2="' . ($w - $padR) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="#e2e8f0" stroke-width="1"/>'
                . '<text x="' . ($padL - 4) . '" y="' . ($yy + 3) . '" text-anchor="end" class="pr-axis">' . e(PrintReport::short($value)) . '</text>';
        }

        foreach ($series as $one) {
            $points = [];

            foreach (array_values($one['values']) as $i => $value) {
                $points[] = round($x($i), 1) . ',' . round($y((float) $value), 1);
            }

            $svg .= '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="' . e($one['color']) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
        }

        if (! empty($labels)) {
            $svg .= '<text x="' . $padL . '" y="' . ($h - 4) . '" class="pr-axis">' . e($labels[0] ?? '') . '</text>'
                . '<text x="' . ($w - $padR) . '" y="' . ($h - 4) . '" text-anchor="end" class="pr-axis">' . e($labels[1] ?? '') . '</text>';
        }

        $svg .= '</svg><div class="pr-lines-legend">';

        foreach ($series as $one) {
            $svg .= '<span><i style="background:' . e($one['color']) . '"></i>' . e($one['label']) . '</span>';
        }

        return new HtmlString($svg . '</div>');
    }

    /** A thin usage bar for a table cell (colour follows the same low / medium / high rule as the screen). */
    public static function meter(float|int|string $percent): HtmlString
    {
        $percent = (float) $percent;
        $width   = min(100, max(0, $percent));

        return new HtmlString('<span class="pr-meter"><span style="width:' . $width . '%;background:' . Usage::color($percent) . '"></span></span>');
    }
}
