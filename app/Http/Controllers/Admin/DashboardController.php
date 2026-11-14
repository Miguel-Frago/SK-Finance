<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Models\ActivityLog;
use App\Models\Barangay;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** A secret code that ends within this many days is listed under "needs attention". */
    private const CODE_WARNING_DAYS = 2;

    public function index(): View
    {
        $counts = [
            'requests_pending' => AccessRequest::where('status', AccessRequest::PENDING)->count(),
            'codes_out'        => AccessRequest::where('status', AccessRequest::APPROVED)->count(),
            'accounts_pending' => User::where('status', User::STATUS_PENDING)->count(),
            'accounts_active'  => User::where('status', User::STATUS_APPROVED)->where('is_active', true)->count(),
            'accounts_off'     => User::where('status', User::STATUS_APPROVED)->where('is_active', false)->count(),
            'barangays'        => Barangay::count(),
            'portals_active'   => Barangay::whereHas('users', fn ($q) => $q
                ->where('role', User::ROLE_TREASURER)->where('status', User::STATUS_APPROVED)->where('is_active', true))->count(),
        ];

        // Needs attention: the oldest waiting requests, accounts waiting for approval, and codes about to run out
        $oldestRequests = AccessRequest::with('barangay')
            ->where('status', AccessRequest::PENDING)
            ->orderBy('created_at')
            ->limit(5)
            ->get();

        $oldestAccounts = User::with('barangay')
            ->where('status', User::STATUS_PENDING)
            ->orderBy('created_at')
            ->limit(5)
            ->get();

        $expiringCodes = AccessRequest::with('barangay')
            ->where('status', AccessRequest::APPROVED)
            ->whereNull('code_used_at')
            ->whereNotNull('code_expires_at')
            ->where('code_expires_at', '<=', now()->addDays(self::CODE_WARNING_DAYS))
            ->orderBy('code_expires_at')
            ->limit(5)
            ->get();

        $recent = ActivityLog::with('barangay')->orderByDesc('id')->limit(10)->get();

        // With MAIL_MAILER=log (or array) every "email sent" is only written to a file: nobody receives anything
        $mailNotSending = in_array(config('mail.default'), ['log', 'array'], true);

        return view('admin.dashboard', compact('counts', 'recent', 'oldestRequests', 'oldestAccounts', 'expiringCodes', 'mailNotSending'));
    }
}
