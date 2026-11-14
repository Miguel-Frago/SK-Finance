<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBarangay;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * One quarter of one year of one barangay. While it is "locked" (or an unlock was asked for but not allowed yet) nothing
 * dated inside it can be added, changed or voided. See App\Support\PeriodLocks for the steps (who asks, who approves).
 */
class PeriodLock extends Model
{
    use BelongsToBarangay;

    public const OPEN             = 'open';
    public const LOCK_REQUESTED   = 'lock_requested';
    public const LOCKED           = 'locked';
    public const UNLOCK_REQUESTED = 'unlock_requested';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime', 'approved_at' => 'datetime', 'unlock_requested_at' => 'datetime', 'unlocked_at' => 'datetime',
        ];
    }

    public function isLocked(): bool
    {
        return in_array($this->status, [self::LOCKED, self::UNLOCK_REQUESTED], true);
    }

    public function label(): string
    {
        return 'Q' . $this->quarter . ' ' . $this->year;
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public static function quarterOf(Carbon $date): int
    {
        return (int) ceil($date->month / 3);
    }

    public static function months(int $quarter): string
    {
        return [1 => 'Jan to Mar', 2 => 'Apr to Jun', 3 => 'Jul to Sep', 4 => 'Oct to Dec'][$quarter] ?? '';
    }

    /** The first and last day of a quarter. @return array{0:Carbon,1:Carbon} */
    public static function bounds(int $year, int $quarter): array
    {
        $start = Carbon::create($year, ($quarter - 1) * 3 + 1, 1)->startOfDay();

        return [$start, $start->copy()->addMonths(2)->endOfMonth()->startOfDay()];
    }

    /** Is this day inside a locked quarter of this barangay? (looked up without the sign-in scope, so it also works from the Admin side) */
    public static function dateLocked(int $barangayId, Carbon $date): ?self
    {
        $lock = static::withoutGlobalScopes()->where('barangay_id', $barangayId)
            ->where('year', $date->year)->where('quarter', self::quarterOf($date))->first();

        return $lock && $lock->isLocked() ? $lock : null;
    }

    /**
     * The last line of defence: called whenever a money record is saved or voided. Looks at the date it has now AND the
     * date it had before, so a record cannot be moved out of (or into) a locked quarter either.
     */
    public static function guardRecord(Model $record): void
    {
        $barangayId = $record->getAttribute('barangay_id');
        if ($barangayId === null) {
            $user = Auth::user();
            $barangayId = $user instanceof User ? $user->portalId() : Barangay::defaultId();
        }

        foreach ([$record->getAttribute('date'), $record->getOriginal('date')] as $date) {
            if (! $date) {
                continue;
            }
            $lock = static::dateLocked((int) $barangayId, Carbon::parse((string) $date));
            if ($lock) {
                throw ValidationException::withMessages(['date' => [
                    $lock->label() . ' (' . self::months($lock->quarter) . ') is locked, so nothing dated in it can be added, changed or voided. '
                    . 'Ask for it to be unlocked first on the Quarter lock page.',
                ]]);
            }
        }
    }
}
