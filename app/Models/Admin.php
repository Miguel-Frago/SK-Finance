<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

/** The SK Federation President. Signs in at /admin/login, never at the barangay sign-in page. */
class Admin extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password'            => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'theme'               => 'array',
        ];
    }

    /**
     * A random temporary password (11 characters, letters and numbers, no look-alikes such as 0/O or 1/l).
     * It is never fixed or guessable: whoever receives it must replace it at the first sign-in.
     */
    public static function temporaryPassword(): string
    {
        $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
        $digits  = '23456789';
        $chars   = [];

        for ($i = 0; $i < 8; $i++) {
            $chars[] = $letters[random_int(0, strlen($letters) - 1)];
        }
        for ($i = 0; $i < 3; $i++) {
            $chars[] = $digits[random_int(0, strlen($digits) - 1)];
        }

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
