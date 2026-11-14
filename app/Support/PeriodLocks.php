<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\PeriodLock;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The quarter lock, step by step:
 *
 *   open ──Treasurer asks──▶ lock_requested ──Chairman approves──▶ locked ──Treasurer or Chairman asks (with a reason)──▶ unlock_requested
 *     ▲                          │ Chairman turns down, or the Treasurer withdraws                                              │
 *     └──────────────────────────┘                                          Admin allows ──▶ open       Admin turns down ──▶ locked
 *
 * Two people must agree to lock, and somebody outside the barangay must agree to unlock. The Admin sees only the barangay,
 * the quarter and the reason: never any money. Every step is written to the activity log.
 */
final class PeriodLocks
{
    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['lock' => [$message]]);
    }

    private static function row(User $user, int $year, int $quarter): PeriodLock
    {
        abort_unless($quarter >= 1 && $quarter <= 4 && $year >= 2000 && $year <= 2100, 404);

        return PeriodLock::withoutGlobalScopes()->firstOrCreate(
            ['barangay_id' => $user->portalId(), 'year' => $year, 'quarter' => $quarter],
            ['status' => PeriodLock::OPEN],
        );
    }

    private static function log(string $action, PeriodLock $lock, string $text): void
    {
        ActivityLog::record($action, $lock, $text);
    }

    /** The Treasurer asks for a FINISHED quarter to be locked. */
    public static function requestLock(User $user, int $year, int $quarter): PeriodLock
    {
        if ($user->role !== User::ROLE_TREASURER) {
            self::fail('Only the SK Treasurer can ask for a quarter to be locked.');
        }
        $lock = self::row($user, $year, $quarter);
        if ($lock->status !== PeriodLock::OPEN) {
            self::fail($lock->label() . ' is already ' . ($lock->status === PeriodLock::LOCK_REQUESTED ? 'waiting for the Chairman' : 'locked') . '.');
        }
        if (PeriodLock::bounds($year, $quarter)[1]->gte(Carbon::today())) {
            self::fail($lock->label() . ' has not ended yet. A quarter can be locked after its last day.');
        }

        $lock->forceFill(['status' => PeriodLock::LOCK_REQUESTED, 'requested_by' => $user->id, 'requested_at' => now(), 'note' => null])->save();
        self::log('lock_requested', $lock, 'Asked for ' . $lock->label() . ' to be locked');

        return $lock;
    }

    /** The Treasurer takes the request back. */
    public static function withdrawLock(User $user, PeriodLock $lock): void
    {
        if ($lock->status !== PeriodLock::LOCK_REQUESTED || $user->role !== User::ROLE_TREASURER) {
            self::fail('There is no request of yours to take back.');
        }
        $lock->forceFill(['status' => PeriodLock::OPEN])->save();
        self::log('lock_withdrawn', $lock, 'Took back the request to lock ' . $lock->label());
    }

    /** The Chairman approves: the quarter is locked. */
    public static function approveLock(User $user, PeriodLock $lock): void
    {
        if ($user->role !== User::ROLE_CHAIRMAN) {
            self::fail('Only the SK Chairman can approve a lock.');
        }
        if ($lock->status !== PeriodLock::LOCK_REQUESTED) {
            self::fail('There is no lock request to approve.');
        }
        $lock->forceFill(['status' => PeriodLock::LOCKED, 'approved_by' => $user->id, 'approved_at' => now(), 'note' => null])->save();
        self::log('locked', $lock, 'Approved the lock of ' . $lock->label());
    }

    /** The Chairman turns the request down (with a short reason). */
    public static function rejectLock(User $user, PeriodLock $lock, string $note): void
    {
        if ($user->role !== User::ROLE_CHAIRMAN || $lock->status !== PeriodLock::LOCK_REQUESTED) {
            self::fail('There is no lock request for you to turn down.');
        }
        $lock->forceFill(['status' => PeriodLock::OPEN, 'note' => $note])->save();
        self::log('lock_rejected', $lock, 'Turned down the lock of ' . $lock->label() . ': ' . $note);
    }

    /** The Treasurer or the Chairman asks the Admin to open a locked quarter again. */
    public static function requestUnlock(User $user, PeriodLock $lock, string $reason): void
    {
        if (! in_array($user->role, [User::ROLE_TREASURER, User::ROLE_CHAIRMAN], true)) {
            self::fail('Only the SK Treasurer or the SK Chairman can ask for an unlock.');
        }
        if ($lock->status !== PeriodLock::LOCKED) {
            self::fail($lock->label() . ' is not locked' . ($lock->status === PeriodLock::UNLOCK_REQUESTED ? ' (an unlock is already waiting for the Admin)' : '') . '.');
        }
        $lock->forceFill([
            'status' => PeriodLock::UNLOCK_REQUESTED, 'unlock_reason' => $reason, 'unlock_requested_by' => $user->id, 'unlock_requested_at' => now(), 'note' => null,
        ])->save();
        self::log('unlock_requested', $lock, 'Asked the Admin to unlock ' . $lock->label() . ': ' . $reason);
    }

    /** The Admin allows it: the quarter is open again. */
    public static function approveUnlock(Admin $admin, PeriodLock $lock): void
    {
        if ($lock->status !== PeriodLock::UNLOCK_REQUESTED) {
            self::fail('There is no unlock request to allow.');
        }
        $lock->forceFill(['status' => PeriodLock::OPEN, 'unlocked_by_admin' => $admin->id, 'unlocked_at' => now()])->save();
        self::log('unlocked', $lock, 'Allowed the unlock of ' . $lock->label() . ' (' . $lock->barangay?->name . ')');
    }

    /** The Admin turns it down: the quarter stays locked. */
    public static function denyUnlock(Admin $admin, PeriodLock $lock, string $note): void
    {
        if ($lock->status !== PeriodLock::UNLOCK_REQUESTED) {
            self::fail('There is no unlock request to turn down.');
        }
        $lock->forceFill(['status' => PeriodLock::LOCKED, 'note' => $note])->save();
        self::log('unlock_denied', $lock, 'Turned down the unlock of ' . $lock->label() . ': ' . $note);
    }

    /** How many lock requests wait for this Chairman's decision. */
    public static function waitingForChairman(): int
    {
        return PeriodLock::query()->where('status', PeriodLock::LOCK_REQUESTED)->count();
    }
}
