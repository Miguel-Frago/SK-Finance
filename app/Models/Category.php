<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBarangay;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use BelongsToBarangay, LogsActivity;

    protected $fillable = ['budget_id', 'parent_id', 'name', 'code', 'description', 'sort_order'];

    /* ------------------------------------------------------------
     | Relationships
     * ------------------------------------------------------------ */

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /** The category this one sits inside (null for a top-level category). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Categories nested directly inside this one. */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    /** Items (budget lines) that belong directly to this category. */
    public function subcategories(): HasMany
    {
        return $this->hasMany(Subcategory::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /* ------------------------------------------------------------
     | Tree helpers
     * ------------------------------------------------------------ */

    /** This category's id plus the ids of everything nested inside it, at any depth. */
    public function treeIds(): array
    {
        $ids      = [$this->id];
        $frontier = [$this->id];

        while ($frontier !== []) {
            $frontier = self::whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids      = array_merge($ids, $frontier);
        }

        return $ids;
    }

    /**
     * Fills in `tree_allocated` and `tree_spent`: this category's own items plus
     * everything nested inside it. Works on an already-loaded tree (see
     * Budget::categoryTree()), so it costs no extra queries.
     */
    public function rollUp(): static
    {
        $allocated = (float) $this->subcategories->sum('allocated_amount');
        $spent     = (float) $this->subcategories->sum('spent');

        foreach ($this->children as $child) {
            $child->rollUp();

            $allocated += $child->tree_allocated;
            $spent     += $child->tree_spent;
        }

        $this->setAttribute('tree_allocated', round($allocated, 2));
        $this->setAttribute('tree_spent', round($spent, 2));

        return $this;
    }

    /* ------------------------------------------------------------
     | Accessors (direct items only; use rollUp() for the whole branch)
     * ------------------------------------------------------------ */

    protected function totalAllocated(): Attribute
    {
        return Attribute::get(fn () => round((float) $this->subcategories()->sum('allocated_amount'), 2));
    }

    protected function totalSpent(): Attribute
    {
        return Attribute::get(fn () => round((float) $this->transactions()->sum('amount'), 2));
    }

    protected function balance(): Attribute
    {
        return Attribute::get(fn () => round($this->total_allocated - $this->total_spent, 2));
    }

    protected function utilizationPercent(): Attribute
    {
        return Attribute::get(function () {
            $allocated = $this->total_allocated;

            return $allocated > 0 ? round(($this->total_spent / $allocated) * 100, 1) : 0.0;
        });
    }
}
