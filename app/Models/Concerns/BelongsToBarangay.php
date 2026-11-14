<?php

namespace App\Models\Concerns;

use App\Models\Barangay;
use App\Models\Scopes\BarangayScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Put this on every model that belongs to one barangay portal. It
 *   1. hides the other barangays' rows from whoever is signed in (BarangayScope), and
 *   2. stamps new rows with the signed-in person's barangay.
 * barangay_id is deliberately NOT mass-assignable, so no form can choose it.
 * Rows created with nobody signed in (seeders, artisan) go to Barangay Cantoria No. 4, like before.
 */
trait BelongsToBarangay
{
    public static function bootBelongsToBarangay(): void
    {
        static::addGlobalScope(new BarangayScope());

        static::creating(function ($model) {
            if ($model->getAttribute('barangay_id') !== null) {
                return;
            }

            $user = Auth::user();

            $model->setAttribute(
                'barangay_id',
                $user instanceof User ? $user->portalId() : Barangay::defaultId()
            );
        });
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }
}
