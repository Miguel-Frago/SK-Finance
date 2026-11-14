<?php

namespace App\Support;

use App\Models\Budget;
use App\Models\FundMovement;

/**
 * "What would the bank and cash balances be if this movement were removed / added?"
 * Shared by the form request (create and edit) and the controller (delete) so the
 * rule "bank and cash never go below zero" lives in one place.
 *
 * Balances are plain arrays: ['bank' => float, 'cash' => float].
 */
class FundBalances
{
    /** The budget's balances with one movement's effect taken out (or as they stand when null). */
    public static function without(Budget $budget, ?FundMovement $movement = null): array
    {
        $bank = (float) $budget->bank_balance;
        $cash = (float) $budget->cash_balance;

        if ($movement) {
            $bank -= $movement->bankEffect();
            $cash -= $movement->cashEffect();
        }

        return ['bank' => round($bank, 2), 'cash' => round($cash, 2)];
    }

    /** Balances after a movement is applied on top of them. */
    public static function plus(array $balances, FundMovement $movement): array
    {
        return [
            'bank' => round($balances['bank'] + $movement->bankEffect(), 2),
            'cash' => round($balances['cash'] + $movement->cashEffect(), 2),
        ];
    }

    /**
     * Clear messages for every account that would end up below zero.
     *
     * @param array $before balances before the new movement is applied (the edited one already removed)
     * @param array $after  balances after the change
     * @param ?FundMovement $new the movement being saved, or null when deleting
     * @param string $action how the message refers to the change, e.g. "This change"
     * @return string[]
     */
    public static function problems(array $before, array $after, ?FundMovement $new, string $action): array
    {
        $problems = [];
        $reported = [];

        if ($new?->type === 'withdrawal' && self::isBelowZero($after['bank'])) {
            $problems[]       = 'A withdrawal cannot exceed the bank balance of ' . self::peso(max(0, $before['bank'])) . '.';
            $reported['bank'] = true;
        }

        if ($new?->type === 'deposit' && self::isBelowZero($after['cash'])) {
            $problems[]       = 'A deposit cannot exceed the cash on hand of ' . self::peso(max(0, $before['cash'])) . '.';
            $reported['cash'] = true;
        }

        $labels = ['bank' => 'the bank account', 'cash' => 'cash on hand'];

        foreach ($labels as $key => $label) {
            if (! isset($reported[$key]) && self::isBelowZero($after[$key])) {
                $problems[] = "{$action} would leave {$label} at -" . self::peso(abs($after[$key]))
                    . '. Expenses or other transfers already rely on this money, so adjust those first.';
            }
        }

        return $problems;
    }

    private static function isBelowZero(float $value): bool
    {
        return round($value * 100) < 0; // compare whole centavos, never raw floats
    }

    private static function peso(float $value): string
    {
        return '₱' . number_format($value, 2);
    }
}
