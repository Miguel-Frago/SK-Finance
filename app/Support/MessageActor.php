<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\User;

/**
 * "Who is talking": a barangay account or the Admin. They live in different tables and use different sign-in guards,
 * so messaging works with this small value object instead of with either model directly.
 */
final class MessageActor
{
    public const USER  = 'user';
    public const ADMIN = 'admin';

    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly string $name,
        public readonly string $role,        // treasurer | chairman | secretary | kagawad | admin
        public readonly string $roleLabel,   // "SK Treasurer", "SK Federation President" ...
        public readonly string $place,       // barangay name, or the municipality for the Admin
        public readonly ?int $barangayId,
    ) {
    }

    public static function fromUser(User $user): self
    {
        return new self(
            self::USER,
            (int) $user->getKey(),
            (string) $user->name,
            $user->role ?? User::ROLE_TREASURER,
            $user->role_label,
            (string) ($user->portal()?->name ?? ''),
            $user->portalId(),
        );
    }

    public static function fromAdmin(Admin $admin): self
    {
        return new self(
            self::ADMIN,
            (int) $admin->getKey(),
            (string) $admin->name,
            'admin',
            Messaging::ADMIN_LABEL,
            Messaging::ADMIN_PLACE,
            null,
        );
    }

    public function isAdmin(): bool
    {
        return $this->type === self::ADMIN;
    }

    public function is(string $type, int $id): bool
    {
        return $this->type === $type && $this->id === $id;
    }

    /** What the other side of a chat is shown as (never an email address or a phone number). */
    public function toArray(): array
    {
        return [
            'type'  => $this->type,
            'id'    => $this->id,
            'name'  => $this->name,
            'role'  => $this->roleLabel,
            'place' => $this->place,
        ];
    }
}
