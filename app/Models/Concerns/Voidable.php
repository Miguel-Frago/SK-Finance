<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use App\Models\PeriodLock;
use Illuminate\Database\Eloquent\Builder;

/**
 * Money records are never deleted. A voided record stays in the books with who voided it, when and why, and is left out
 * of every total, ledger, journal and report automatically (a global scope on the model, so no query can forget it).
 * Use Model::withoutGlobalScope('notVoided') only to LIST voided records.
 */
trait Voidable
{
    public static function bootVoidable(): void
    {
        static::addGlobalScope('notVoided', function (Builder $builder) {
            $builder->whereNull($builder->getModel()->getTable() . '.voided_at');
        });
    }

    public function initializeVoidable(): void
    {
        $this->mergeCasts(['voided_at' => 'datetime']);
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /** Voids the record. Refused (with a clear message) when its quarter is locked. */
    public function voidWith(string $reason, int $userId): void
    {
        PeriodLock::guardRecord($this);

        $this->forceFill(['voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason])->saveQuietly();

        ActivityLog::record('voided', $this, 'Voided: ' . $reason);
    }
}
