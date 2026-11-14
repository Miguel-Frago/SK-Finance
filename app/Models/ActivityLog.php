<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Throwable;

/** One line of "who did what, when, in which barangay". Write with ActivityLog::record(). */
class ActivityLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'details'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function barangay()
    {
        return $this->belongsTo(Barangay::class);
    }

    /**
     * Add a line to the log. It never breaks the action being logged: if writing fails, the error is
     * reported and the person carries on.
     */
    public static function record(string $action, ?Model $subject = null, ?string $description = null, array $details = []): void
    {
        try {
            // On the Admin pages the actor is the Admin (a barangay account may be signed in in the same browser)
            $admin = request()->is('admin', 'admin/*') ? Auth::guard('admin')->user() : null;

            $user = $admin ? null : Auth::user();
            $user = $user instanceof User ? $user : null;

            $label = $subject && method_exists($subject, 'activityLabel') ? $subject->activityLabel() : null;

            if ($subject instanceof Barangay) {
                $barangayId = $subject->getKey();
            } else {
                $barangayId = $subject?->getAttribute('barangay_id') ?? $user?->barangay_id;
            }

            static::create([
                'barangay_id'  => $barangayId,
                'user_id'      => $user?->getKey(),
                'user_name'    => $admin ? 'Admin ' . $admin->name : $user?->name,
                'user_role'    => $admin ? 'admin' : $user?->role,
                'action'       => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id'   => $subject?->getKey(),
                'description'  => $description ?? ucfirst($action) . ($label ? ' ' . $label : ''),
                'details'      => $details === [] ? null : $details,
                'ip_address'   => app()->runningInConsole() ? null : request()->ip(),
                'created_at'   => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
