<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBarangay;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/** One set of minutes of a meeting of the Sangguniang Kabataan of a barangay. Written by the SK Secretary. */
class MeetingMinute extends Model
{
    use BelongsToBarangay, LogsActivity;

    public const TYPES = [
        'regular'  => 'Regular meeting',
        'special'  => 'Special meeting',
        'assembly' => 'Katipunan ng Kabataan assembly',
    ];

    public const DRAFT = 'draft';
    public const FINAL = 'final';

    public const STATUSES = [
        self::DRAFT => 'Draft',
        self::FINAL => 'Final',
    ];

    protected $fillable = [
        'meeting_type', 'title', 'meeting_date', 'start_time', 'end_time', 'venue', 'presiding',
        'present', 'absent', 'agenda', 'discussion', 'resolutions', 'next_meeting',
        'prepared_by', 'attested_by', 'status',
    ];

    protected function casts(): array
    {
        return ['meeting_date' => 'date'];
    }

    protected function typeLabel(): Attribute
    {
        return Attribute::get(fn () => self::TYPES[$this->meeting_type] ?? $this->meeting_type);
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn () => self::STATUSES[$this->status] ?? $this->status);
    }

    /** "8:00 AM to 10:30 AM" (either end may be missing). */
    protected function timeLabel(): Attribute
    {
        return Attribute::get(function () {
            $fmt = fn (?string $hm) => $hm ? \Carbon\Carbon::createFromFormat('H:i', $hm)->format('g:i A') : null;

            return collect([$fmt($this->start_time), $fmt($this->end_time)])->filter()->implode(' to ');
        });
    }

    public function isFinal(): bool
    {
        return $this->status === self::FINAL;
    }

    public function activityLabel(): string
    {
        return 'Minutes "' . $this->title . '"';
    }
}
