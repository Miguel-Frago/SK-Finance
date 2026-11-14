<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBarangay;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class Budget extends Model
{
    use BelongsToBarangay, LogsActivity;

    protected $fillable = [
        'year',
        'type',
        'label',
        'carried_from_budget_id',
        'total_budget',
        'opening_bank_balance',
        'opening_cash_balance',
    ];

    protected $casts = [
        'year'                 => 'integer',
        'total_budget'         => 'decimal:2',
        'opening_bank_balance' => 'decimal:2',
        'opening_cash_balance' => 'decimal:2',
    ];

    /* ------------------------------------------------------------
     | Relationships
     * ------------------------------------------------------------ */

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function subcategories(): HasManyThrough
    {
        return $this->hasManyThrough(Subcategory::class, Category::class);
    }

    public function transactions(): HasManyThrough
    {
        return $this->hasManyThrough(Transaction::class, Category::class);
    }

    public function fundMovements(): HasMany
    {
        return $this->hasMany(FundMovement::class);
    }

    /** The earlier budget this supplemental was carried over from. */
    public function carriedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'carried_from_budget_id');
    }

    /** Supplemental budgets created from this budget's leftover funds. */
    public function supplementals(): HasMany
    {
        return $this->hasMany(self::class, 'carried_from_budget_id');
    }

    /**
     * The budget's categories as a nested tree (top-level categories, each with a
     * `children` relation). Every category also gets `tree_allocated` / `tree_spent`
     * (its own items + everything nested inside). Loads in 2 queries, no matter how deep.
     */
    public function categoryTree(): Collection
    {
        $categories = $this->categories()
            ->with(['subcategories' => fn ($query) => $query->withSum('transactions', 'amount')->orderBy('id')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $byParent = $categories->groupBy(fn (Category $category) => $category->parent_id ?? 0);

        $attach = function (int $parentId) use (&$attach, $byParent) {
            $children = $byParent->get($parentId, collect())->values();

            foreach ($children as $category) {
                $category->setRelation('children', $attach($category->id));
            }

            return $children;
        };

        $roots = $attach(0);

        foreach ($roots as $root) {
            $root->rollUp();
        }

        return $roots;
    }

    /* ------------------------------------------------------------
     | Scopes
     * ------------------------------------------------------------ */

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('year')->orderByRaw("type = 'regular' DESC");
    }

    /**
     * The budget the treasurer is currently working in (chosen with the switcher,
     * remembered in the session). Falls back to the newest budget.
     */
    public static function current(): ?self
    {
        $id = session('budget_id');
        $budget = $id ? self::find($id) : null;

        return $budget ?? self::latestFirst()->first();
    }

    /* ------------------------------------------------------------
     | Accessors (all computed on demand, never stored)
     * ------------------------------------------------------------ */

    protected function isSupplemental(): Attribute
    {
        return Attribute::get(fn () => $this->type === 'supplemental');
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(function () {
            if ($this->label) {
                return $this->label;
            }

            return $this->year . ' ' . ($this->is_supplemental ? 'Supplemental' : 'Regular');
        });
    }

    /** Sum of all subcategory allocations. */
    protected function totalAllocated(): Attribute
    {
        return Attribute::get(fn () => $this->money($this->subcategories()->sum('subcategories.allocated_amount')));
    }

    /** Budget not yet assigned to any subcategory. */
    protected function unallocated(): Attribute
    {
        return Attribute::get(fn () => $this->money((float) $this->total_budget - $this->total_allocated));
    }

    protected function totalSpent(): Attribute
    {
        return Attribute::get(fn () => $this->money($this->transactions()->sum('transactions.amount')));
    }

    /** Appropriation left (total budget minus everything spent). */
    protected function remainingBudget(): Attribute
    {
        return Attribute::get(fn () => $this->money((float) $this->total_budget - $this->total_spent));
    }

    protected function utilizationPercent(): Attribute
    {
        return Attribute::get(function () {
            $total = (float) $this->total_budget;

            return $total > 0 ? round(($this->total_spent / $total) * 100, 1) : 0.0;
        });
    }

    protected function bankSpent(): Attribute
    {
        return Attribute::get(fn () => $this->spentVia('bank'));
    }

    protected function cashSpent(): Attribute
    {
        return Attribute::get(fn () => $this->spentVia('cash_on_hand'));
    }

    /**
     * Bank = opening + bank receipts + deposits - withdrawals - bank spending
     */
    protected function bankBalance(): Attribute
    {
        return Attribute::get(function () {
            return $this->money(
                (float) $this->opening_bank_balance
                + $this->movementTotal('receipt', 'bank')
                + $this->movementTotal('deposit')
                - $this->movementTotal('withdrawal')
                - $this->spentVia('bank')
            );
        });
    }

    /**
     * Cash on hand = opening + cash receipts + withdrawals - deposits - cash spending
     */
    protected function cashBalance(): Attribute
    {
        return Attribute::get(function () {
            return $this->money(
                (float) $this->opening_cash_balance
                + $this->movementTotal('receipt', 'cash_on_hand')
                + $this->movementTotal('withdrawal')
                - $this->movementTotal('deposit')
                - $this->spentVia('cash_on_hand')
            );
        });
    }

    protected function totalFundsOnHand(): Attribute
    {
        return Attribute::get(fn () => $this->money($this->bank_balance + $this->cash_balance));
    }

    /* ------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------ */

    public function spentVia(string $paymentMethod): float
    {
        return $this->money(
            $this->transactions()->where('transactions.payment_method', $paymentMethod)->sum('transactions.amount')
        );
    }

    /** The bank balance as the books stood on the end of a date (voided records are not counted, like everywhere else). */
    public function bankBalanceAsOf(\Carbon\CarbonInterface|string $date): float
    {
        $day = \Carbon\Carbon::parse($date)->toDateString();
        $moves = fn (string $type, ?string $account = null) => (float) $this->fundMovements()
            ->where('type', $type)->when($account, fn ($q) => $q->where('account', $account))->whereDate('date', '<=', $day)->sum('amount');
        $spent = (float) $this->transactions()->where('transactions.payment_method', 'bank')->whereDate('transactions.date', '<=', $day)->sum('transactions.amount');

        return $this->money((float) $this->opening_bank_balance + $moves('receipt', 'bank') + $moves('deposit') - $moves('withdrawal') - $spent);
    }

    public function movementTotal(string $type, ?string $account = null): float
    {
        $query = $this->fundMovements()->where('type', $type);

        if ($account !== null) {
            $query->where('account', $account);
        }

        return $this->money($query->sum('amount'));
    }

    /**
     * What a supplemental budget created from this budget would start with.
     * Used to pre-fill the "create supplemental" form; the treasurer can adjust it.
     */
    public function carryOverPreview(): array
    {
        return [
            'total_budget'         => max(0, $this->remaining_budget),
            'opening_bank_balance' => max(0, $this->bank_balance),
            'opening_cash_balance' => max(0, $this->cash_balance),
        ];
    }

    /**
     * Create a separate supplemental budget from this budget's unspent funds.
     * It gets its own categories, allocations, balances and fund movements,
     * so it never mixes with the regular budget of the new year.
     */
    public function createSupplemental(int $year, ?string $label = null, array $overrides = []): self
    {
        if ($year <= $this->year) {
            throw new InvalidArgumentException('A supplemental budget must be for a later year than its source budget.');
        }

        if (self::where('year', $year)->where('type', 'supplemental')->exists()) {
            throw new InvalidArgumentException("A supplemental budget for {$year} already exists.");
        }

        return self::create(array_merge($this->carryOverPreview(), [
            'year'                   => $year,
            'type'                   => 'supplemental',
            'label'                  => $label ?? "Supplemental {$year} (from {$this->year})",
            'carried_from_budget_id' => $this->id,
        ], $overrides));
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
