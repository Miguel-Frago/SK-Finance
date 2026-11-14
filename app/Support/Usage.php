<?php

namespace App\Support;

/**
 * One place that decides how "how much has been spent" is coloured.
 *
 *   under 50%   -> low    (yellow)
 *   50% to 79%  -> medium (orange)
 *   80% and up  -> high   (red)
 *
 * 80% matches the "Items nearly used up" list on the dashboard.
 * Change the two numbers below and every bar, ring and chart follows.
 */
class Usage
{
    public const MEDIUM_FROM = 50;
    public const HIGH_FROM   = 80;

    public const COLORS = [
        'low'    => '#eab308',
        'medium' => '#f97316',
        'high'   => '#dc2626',
    ];

    /** 'low', 'medium' or 'high' for a percentage of the amount that has been spent. */
    public static function level(float|int|string|null $percent): string
    {
        $percent = (float) $percent;

        return match (true) {
            $percent >= self::HIGH_FROM   => 'high',
            $percent >= self::MEDIUM_FROM => 'medium',
            default                       => 'low',
        };
    }

    public static function color(float|int|string|null $percent): string
    {
        return self::COLORS[self::level($percent)];
    }
}
