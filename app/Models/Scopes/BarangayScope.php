<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * The wall between barangays. While someone is signed in, every query on a portal table (budgets, categories,
 * items, expenses, fund movements, report settings) only sees the rows of THEIR barangay, including route
 * model binding, so a link to another barangay's record is a 404. Outside a signed-in request (seeders,
 * artisan) nothing is filtered.
 */
class BarangayScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        // portalId() is never empty for a real account; 0 matches nothing, so a broken account sees nothing
        $builder->where($model->getTable() . '.barangay_id', $user->portalId() ?? 0);
    }
}
