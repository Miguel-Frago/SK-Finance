<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];   // 'role' is NOT fillable on purpose: nobody can pick their own role from a form

    public const ROLE_TREASURER = 'treasurer';
    public const ROLE_CHAIRMAN  = 'chairman';
    public const ROLE_SECRETARY = 'secretary';
    public const ROLE_KAGAWAD   = 'kagawad';

    public const ROLES = [
        self::ROLE_TREASURER => 'SK Treasurer',
        self::ROLE_CHAIRMAN  => 'SK Chairman',
        self::ROLE_SECRETARY => 'SK Secretary',
        self::ROLE_KAGAWAD   => 'SK Kagawad',
    ];

    public const STATUS_PENDING  = 'pending';    // made the account, waiting for the Admin
    public const STATUS_APPROVED = 'approved';   // the Admin approved: can sign in (while is_active)

    /** Every barangay role can be asked for through "Request access". */
    public const SIGNUP_ROLES = [
        self::ROLE_TREASURER => 'SK Treasurer',
        self::ROLE_CHAIRMAN  => 'SK Chairman',
        self::ROLE_SECRETARY => 'SK Secretary',
        self::ROLE_KAGAWAD   => 'SK Kagawad',
    ];

    /** How many ACTIVE accounts of each role one barangay can have at the same time. */
    public const SEATS = [
        self::ROLE_TREASURER => 1,
        self::ROLE_CHAIRMAN  => 1,
        self::ROLE_SECRETARY => 1,
        self::ROLE_KAGAWAD   => 7,
    ];

    /** Approved, switched-on accounts of a role in a barangay (not counting one account, so it can be checked against itself). */
    public static function seatsUsed(int $barangayId, string $role, ?int $exceptUserId = null): int
    {
        return static::query()
            ->where('barangay_id', $barangayId)
            ->where('role', $role)
            ->where('status', self::STATUS_APPROVED)
            ->where('is_active', true)
            ->when($exceptUserId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->count();
    }

    public static function seatAvailable(int $barangayId, string $role, ?int $exceptUserId = null): bool
    {
        return static::seatsUsed($barangayId, $role, $exceptUserId) < (self::SEATS[$role] ?? 1);
    }

    /** Why a place cannot be given out right now (for the Admin), or null when there is a free place. */
    public static function seatBlock(int $barangayId, string $role, ?int $exceptUserId = null): ?string
    {
        if (static::seatAvailable($barangayId, $role, $exceptUserId)) {
            return null;
        }

        $barangay = Barangay::find($barangayId)?->name ?? 'This barangay';
        $label    = self::ROLES[$role] ?? $role;
        $seats    = self::SEATS[$role] ?? 1;

        if ($seats === 1) {
            $holder = static::activeHolder($barangayId, $role, $exceptUserId);

            return "{$barangay} already has an active {$label}" . ($holder ? " ({$holder->name})" : '') . '. Switch that account off first.';
        }

        return "{$barangay} already has all {$seats} {$label} places filled. Switch one of those accounts off first.";
    }

    /** The request this account was made from (name, email and position as the Admin approved them). */
    public function accessRequest(): BelongsTo
    {
        return $this->belongsTo(AccessRequest::class);
    }

    /** The account that currently holds a place (Treasurer or Chairman) in a barangay, if any. */
    public static function activeHolder(int $barangayId, string $role, ?int $exceptUserId = null): ?self
    {
        return static::query()
            ->where('barangay_id', $barangayId)
            ->where('role', $role)
            ->where('status', self::STATUS_APPROVED)
            ->where('is_active', true)
            ->when($exceptUserId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->first();
    }

    /** The barangay portal this account belongs to (one account, one barangay). */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /**
     * The barangay this account works in. An account with no barangay saved is treated as Cantoria No. 4,
     * which is where every account was before barangays existed.
     */
    public function portalId(): ?int
    {
        return $this->barangay_id !== null ? (int) $this->barangay_id : Barangay::defaultId();
    }

    public function portal(): ?Barangay
    {
        return $this->barangay ?? ($this->portalId() ? Barangay::find($this->portalId()) : null);
    }

    /** False once the account has been switched off (term ended, resigned). Unsaved accounts count as active. */
    public function isActive(): bool
    {
        return $this->is_active ?? true;
    }

    /** The Admin has approved the account. Accounts that exist from before verification count as approved. */
    public function isApproved(): bool
    {
        return ($this->status ?? self::STATUS_APPROVED) === self::STATUS_APPROVED;
    }

    /** Approved and switched on: the only accounts that can sign in (password or QR Access ID). */
    public function canSignIn(): bool
    {
        return $this->isApproved() && $this->isActive();
    }

    /** Treasurer = full access. Chairman = view, print and edit the report, and edit finance records only while the treasurer allows it. */
    public function isTreasurer(): bool
    {
        return ($this->role ?? self::ROLE_TREASURER) === self::ROLE_TREASURER;
    }

    public function isChairman(): bool
    {
        return $this->role === self::ROLE_CHAIRMAN;
    }

    public function isSecretary(): bool
    {
        return $this->role === self::ROLE_SECRETARY;
    }

    /** Kagawads (and, for now, the Secretary) only look at the finances. They never add or change anything. */
    public function isKagawad(): bool
    {
        return $this->role === self::ROLE_KAGAWAD;
    }

    /** Who may add, edit and delete budgets, expenses and fund movements. */
    public function canManageFinance(): bool
    {
        return $this->isTreasurer()
            || ($this->isChairman() && (bool) $this->portal()?->chairman_can_edit);
    }

    /** The Treasurer is excluded from minutes; Chairman, Secretary and Kagawad may read them. */
    public function canViewMinutes(): bool
    {
        return $this->isChairman() || $this->isSecretary() || $this->isKagawad();
    }

    /** Only the SK Secretary writes the minutes of meetings. */
    public function canManageMinutes(): bool
    {
        return $this->isSecretary();
    }

    /** Printing the minutes: the SK Secretary and the SK Chairman. */
    public function canPrintMinutes(): bool
    {
        return $this->isSecretary() || $this->isChairman();
    }

    /** Printing and saving as PDF: the treasurer and the SK chairman. */
    public function canPrint(): bool
    {
        return $this->isTreasurer() || $this->isChairman();
    }

    /** Who may change the names and signatures on the report: the treasurer and the SK chairman. */
    public function canEditReport(): bool
    {
        return $this->isTreasurer() || $this->isChairman();
    }

    public function getRoleLabelAttribute(): string
    {
        return self::ROLES[$this->role ?? self::ROLE_TREASURER] ?? 'SK Treasurer';
    }

    /** Address of the profile photo, or null when there is none. */
    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? asset($this->photo_path) : null;
    }

    /** Position printed on the Access ID: the one typed in the profile, otherwise the role. */
    public function getCardPositionAttribute(): string
    {
        return filled($this->position) ? $this->position : $this->role_label;
    }

    /** True when this account has a QR Access ID (card + PIN) set up. */
    public function hasAccessId(): bool
    {
        return filled($this->qr_token_hash) && filled($this->qr_pin);
    }

    protected $hidden = [
        'password',
        'remember_token',
        'qr_token_hash',
        'qr_pin',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'qr_pin'            => 'hashed',
            'qr_issued_at'      => 'datetime',
            'theme'             => 'array',
            'is_active'         => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at'  => 'datetime',
            'deactivated_at'    => 'datetime',
            'approved_at'       => 'datetime',
        ];
    }
}