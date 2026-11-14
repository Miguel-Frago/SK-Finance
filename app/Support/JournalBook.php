<?php

namespace App\Support;

use App\Models\Budget;
use App\Models\FundMovement;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * The journal of one budget: every expense and every fund movement in date order,
 * each with its effect on the bank and on cash on hand and the running balance of both
 * accounts after it, starting from the opening balances.
 *
 * The running balances are always worked out over ALL entries; filtering and paging
 * happen afterwards, so a filtered row still shows the true balance.
 *
 * Each entry is an array:
 *   key, id, source, source_label, date (Y-m-d), date_label, reference, particulars, detail, url,
 *   bank / cash        signed effect on the account (+ money in = debit, - money out = credit),
 *   bank_balance / cash_balance   balance of the account after this entry.
 */
class JournalBook
{
    public const SOURCES = [
        'expense'    => 'Expense',
        'receipt'    => 'Funds Received',
        'withdrawal' => 'Bank Withdrawal',
        'deposit'    => 'Cash Deposit',
    ];

    /** Entries on the same day: money coming in and moving first, then the expenses paid with it. */
    private const PRIORITY = ['receipt' => 1, 'withdrawal' => 2, 'deposit' => 3, 'expense' => 4];

    public static function entries(Budget $budget): Collection
    {
        $paths = CategoryPaths::forBudget($budget);
        $rows  = collect();

        $expenses = Transaction::query()->filter(['budget_id' => $budget->id])->with('subcategory')->get();

        foreach ($expenses as $expense) {
            $rows->push(self::expenseRow($expense, $paths));
        }

        foreach ($budget->fundMovements()->get() as $movement) {
            $rows->push(self::movementRow($movement));
        }

        $bank = (float) $budget->opening_bank_balance;
        $cash = (float) $budget->opening_cash_balance;

        return $rows
            ->sort(fn (array $a, array $b) => [$a['date'], $a['priority'], $a['id']] <=> [$b['date'], $b['priority'], $b['id']])
            ->values()
            ->map(function (array $row) use (&$bank, &$cash) {
                $bank = round($bank + $row['bank'], 2);
                $cash = round($cash + $row['cash'], 2);

                return $row + ['bank_balance' => $bank, 'cash_balance' => $cash];
            });
    }

    private static function expenseRow(Transaction $expense, array $paths): array
    {
        $amount = (float) $expense->amount;
        $detail = array_filter([
            $expense->payee,
            $expense->subcategory?->display_name,
            $paths[$expense->category_id] ?? null,
        ]);

        return [
            'key'          => 'expense-' . $expense->id,
            'id'           => $expense->id,
            'priority'     => self::PRIORITY['expense'],
            'source'       => 'expense',
            'source_label' => self::SOURCES['expense'],
            'date'         => $expense->date->toDateString(),
            'date_label'   => $expense->date->format('M d, Y'),
            'reference'    => $expense->voucher_number ? 'Voucher ' . $expense->voucher_number
                : ($expense->check_number ? 'Check ' . $expense->check_number : null),
            'particulars'  => $expense->description,
            'detail'       => implode(' · ', $detail),
            'url'          => route('transactions.edit', $expense),
            'bank'         => $expense->payment_method === 'bank' ? -$amount : 0.0,
            'cash'         => $expense->payment_method === 'cash_on_hand' ? -$amount : 0.0,
        ];
    }

    private static function movementRow(FundMovement $movement): array
    {
        return [
            'key'          => 'movement-' . $movement->id,
            'id'           => $movement->id,
            'priority'     => self::PRIORITY[$movement->type] ?? 9,
            'source'       => $movement->type,
            'source_label' => self::SOURCES[$movement->type] ?? $movement->type,
            'date'         => $movement->date->toDateString(),
            'date_label'   => $movement->date->format('M d, Y'),
            'reference'    => $movement->reference,
            'particulars'  => $movement->remarks ?: $movement->type_label,
            'detail'       => $movement->account_label ? 'Into ' . $movement->account_label : '',
            'url'          => route('fund-movements.edit', $movement),
            'bank'         => $movement->bankEffect(),
            'cash'         => $movement->cashEffect(),
        ];
    }
}
