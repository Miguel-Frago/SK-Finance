<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A person asking to join a barangay portal as SK Treasurer or SK Chairman (see the create_access_requests migration). */
class AccessRequest extends Model
{
    public const PENDING    = 'pending';     // waiting for the Admin
    public const APPROVED   = 'approved';    // the Admin emailed a secret code
    public const REGISTERED = 'registered';  // the code was used to create an account
    public const DENIED     = 'denied';

    public const STATUSES = [
        self::PENDING    => 'Waiting for review',
        self::APPROVED   => 'Code sent',
        self::REGISTERED => 'Account created',
        self::DENIED     => 'Denied',
    ];

    /** No 0/O, 1/I/L: easy to read out loud and type. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    // status, code and review fields are set by the Admin screens only, never from a form
    protected $fillable = ['email', 'last_name', 'first_name', 'middle_name', 'barangay_id', 'role'];

    protected function casts(): array
    {
        return [
            'code_expires_at' => 'datetime',
            'code_used_at'    => 'datetime',
            'last_message_at' => 'datetime',
            'reviewed_at'     => 'datetime',
        ];
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /** The account made from this request (once the secret code was used). */
    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'access_request_id');
    }

    /** Requests that are still alive: waiting, or holding a code that has not been used. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PENDING, self::APPROVED]);
    }

    /** "Juan Santos Dela Cruz" */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim(implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name]))));
    }

    /** "Dela Cruz, Juan Santos" (how the Admin's lists are sorted and read) */
    protected function listName(): Attribute
    {
        return Attribute::get(fn () => $this->last_name . ', ' . trim($this->first_name . ' ' . $this->middle_name));
    }

    protected function roleLabel(): Attribute
    {
        return Attribute::get(fn () => User::ROLES[$this->role] ?? $this->role);
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn () => self::STATUSES[$this->status] ?? $this->status);
    }

    /** A fresh secret code like "K7M2P-9QX4R". Only its hash is kept. */
    public static function newCode(): string
    {
        $alphabet = self::CODE_ALPHABET;
        $code     = '';

        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return substr($code, 0, 5) . '-' . substr($code, 5);
    }

    /** Case, spaces and the dash do not matter when a code is typed. */
    public static function hashCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }

    public function codeMatches(string $code): bool
    {
        return filled($this->code_hash) && hash_equals($this->code_hash, self::hashCode($code));
    }
}
