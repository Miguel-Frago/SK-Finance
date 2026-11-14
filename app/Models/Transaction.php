<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBarangay;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\Voidable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use BelongsToBarangay, LogsActivity, Voidable;

    public const PAYMENT_METHODS = [
        'bank'         => 'Bank',
        'cash_on_hand' => 'Cash on Hand',
    ];

    protected $fillable = [
        'date',
        'description',
        'payee',
        'category_id',
        'subcategory_id',
        'amount',
        'payment_method',
        'voucher_number',
        'check_number',
    ];

    protected $casts = [
        'date'   => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        // a finished, locked quarter cannot be touched: not added to, changed, or voided
        static::saving(fn (Transaction $transaction) => PeriodLock::guardRecord($transaction));

        // Remove every receipt file when a transaction is deleted (each attachment deletes its own file)
        static::deleting(function (Transaction $transaction) {
            $transaction->attachments->each->delete();
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }

    protected function paymentMethodLabel(): Attribute
    {
        return Attribute::get(fn () => self::PAYMENT_METHODS[$this->payment_method] ?? $this->payment_method);
    }

    /** The receipts (proof files) of this expense, oldest first. */
    public function attachments(): HasMany
    {
        return $this->hasMany(TransactionAttachment::class)->orderBy('id');
    }

    /**
     * Filters used by the transactions page, journal and ledger.
     * Supported keys: budget_id, category_id, subcategory_id, payment_method, date_from, date_to, search
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['budget_id'] ?? null, fn ($q, $id) => $q->whereHas('category', fn ($c) => $c->where('budget_id', $id)))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['subcategory_id'] ?? null, fn ($q, $id) => $q->where('subcategory_id', $id))
            ->when($filters['payment_method'] ?? null, fn ($q, $method) => $q->where('payment_method', $method))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('date', '<=', $date))
            ->when($filters['search'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('description', 'like', "%{$term}%")
                      ->orWhere('voucher_number', 'like', "%{$term}%")
                      ->orWhere('payee', 'like', "%{$term}%");
                });
            });
    }
}
