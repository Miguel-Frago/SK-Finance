<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBarangay;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Subcategory extends Model
{
    use BelongsToBarangay, LogsActivity;

    protected $fillable = ['category_id', 'name', 'allocated_amount', 'code'];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Total spent. Uses withSum('transactions', 'amount') when it was eager-loaded
     * (avoids N+1 queries on list pages), otherwise queries on demand.
     */
    protected function spent(): Attribute
    {
        return Attribute::get(function () {
            $sum = array_key_exists('transactions_sum_amount', $this->attributes)
                ? $this->attributes['transactions_sum_amount']
                : $this->transactions()->sum('amount');

            return round((float) $sum, 2);
        });
    }

    protected function balance(): Attribute
    {
        return Attribute::get(fn () => round((float) $this->allocated_amount - $this->spent, 2));
    }

    protected function utilizationPercent(): Attribute
    {
        return Attribute::get(function () {
            $allocated = (float) $this->allocated_amount;

            return $allocated > 0 ? round(($this->spent / $allocated) * 100, 1) : 0.0;
        });
    }

    protected function isOverspent(): Attribute
    {
        return Attribute::get(fn () => $this->balance < 0);
    }

    /** "ABYIP-101 · Youth Summit" style label for dropdowns. */
    protected function displayName(): Attribute
    {
        return Attribute::get(fn () => $this->code ? "{$this->code} · {$this->name}" : $this->name);
    }

    /**
     * Ledger rows for this subcategory in chronological order, each with a running balance:
     * allocation minus cumulative spending.
     */
    public function ledgerEntries(): Collection
    {
        $balance = (float) $this->allocated_amount;

        return $this->transactions()
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(function (Transaction $transaction) use (&$balance) {
                $balance = round($balance - (float) $transaction->amount, 2);
                $transaction->running_balance = $balance;

                return $transaction;
            });
    }
}
