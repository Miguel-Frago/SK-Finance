<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBarangay;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Voidable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FundMovement extends Model
{
    use BelongsToBarangay, LogsActivity, Voidable;

    public const TYPES = [
        'receipt'    => 'Funds Received',
        'withdrawal' => 'Bank Withdrawal (Bank → Cash)',
        'deposit'    => 'Cash Deposit (Cash → Bank)',
    ];

    /** Accounts a receipt can go into (withdrawals and deposits have no account). */
    public const ACCOUNTS = [
        'bank'         => 'Bank',
        'cash_on_hand' => 'Cash on Hand',
    ];

    protected $fillable = ['budget_id', 'date', 'type', 'account', 'amount', 'reference', 'remarks'];

    protected $casts = [
        'date'   => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::saving(fn (FundMovement $movement) => PeriodLock::guardRecord($movement));
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    protected function typeLabel(): Attribute
    {
        return Attribute::get(fn () => self::TYPES[$this->type] ?? $this->type);
    }

    protected function accountLabel(): Attribute
    {
        return Attribute::get(fn () => self::ACCOUNTS[$this->account] ?? null);
    }

    /** Signed effect of this movement on the bank balance. */
    public function bankEffect(): float
    {
        $amount = (float) $this->amount;

        return match ($this->type) {
            'receipt'    => $this->account === 'bank' ? $amount : 0.0,
            'withdrawal' => -$amount,
            'deposit'    => $amount,
            default      => 0.0,
        };
    }

    /** Signed effect of this movement on the cash-on-hand balance. */
    public function cashEffect(): float
    {
        $amount = (float) $this->amount;

        return match ($this->type) {
            'receipt'    => $this->account === 'cash_on_hand' ? $amount : 0.0,
            'withdrawal' => $amount,
            'deposit'    => -$amount,
            default      => 0.0,
        };
    }

    /**
     * Filters used by the fund movements page.
     * Supported keys: budget_id, type, account (the account a receipt went into), date_from, date_to, search
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['budget_id'] ?? null, fn ($q, $id) => $q->where('budget_id', $id))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['account'] ?? null, fn ($q, $account) => $q->where('account', $account))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('date', '<=', $date))
            ->when($filters['search'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('reference', 'like', "%{$term}%")
                      ->orWhere('remarks', 'like', "%{$term}%");
                });
            });
    }
}
