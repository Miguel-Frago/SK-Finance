<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One of the 40 barangays of Luna, La Union. Each has exactly one SK Finance portal. */
class Barangay extends Model
{
    use LogsActivity;

    /** Records made with nobody signed in, and accounts from before barangays existed, belong here. */
    public const DEFAULT_SLUG = 'cantoria-no-4';

    protected $fillable = ['name', 'district'];   // chairman_can_edit is set by the treasurer's switch only

    protected function casts(): array
    {
        return ['chairman_can_edit' => 'boolean'];
    }

    public static function defaultId(): ?int
    {
        $id = static::query()->where('slug', self::DEFAULT_SLUG)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** "Brgy. Cantoria No. 4, Luna, La Union", as printed on the Access ID and the report heading. */
    protected function cardLabel(): Attribute
    {
        return Attribute::get(fn () => 'Brgy. ' . $this->name . ', Luna, La Union');
    }

    /** The portal is "active" once it has a working SK Treasurer account. */
    public function isActive(): bool
    {
        return $this->users()
            ->where('role', User::ROLE_TREASURER)
            ->where('status', User::STATUS_APPROVED)
            ->where('is_active', true)
            ->exists();
    }

    public function activityLabel(): string
    {
        return 'Barangay ' . $this->name;
    }
}
