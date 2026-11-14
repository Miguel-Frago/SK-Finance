<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** Small helpers shared by the three ways a barangay account's password changes. */
final class Passwords
{
    /** The rule for a password somebody chooses. */
    public static function rule(): Password
    {
        return Password::min(8)->letters()->numbers();
    }

    /** A temporary password that is easy to read out loud: no 0/O, 1/l/I. Always has letters and digits. */
    public static function temporary(): string
    {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
        $digits = '23456789';
        $pick = fn (string $set) => $set[random_int(0, strlen($set) - 1)];

        $chars = [];
        for ($i = 0; $i < 7; $i++) {
            $chars[] = $pick($letters);
        }
        for ($i = 0; $i < 3; $i++) {
            $chars[] = $pick($digits);
        }
        shuffle($chars);

        return implode('', $chars);
    }

    /** Sets a new password and signs the account out everywhere else. */
    public static function set(User $user, string $password, bool $mustChange): void
    {
        $user->forceFill([
            'password'             => $password,                      // hashed by the model
            'must_change_password' => $mustChange,
            'password_changed_at'  => $mustChange ? null : now(),
            'remember_token'       => Str::random(60),
        ])->save();

        self::endOtherSessions($user);
    }

    /** With database sessions, every browser this account was signed in on is signed out. */
    public static function endOtherSessions(User $user, ?string $keepSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($keepSessionId, fn ($q) => $q->where('id', '!=', $keepSessionId))
            ->delete();
    }
}
