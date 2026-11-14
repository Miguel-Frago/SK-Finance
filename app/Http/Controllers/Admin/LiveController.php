<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Answers the Admin pages' quiet check (public/js/live.js, every few seconds): "has anything changed?".
 * It returns a short fingerprint of the data the Admin screens show. When the fingerprint changes, the page
 * fetches itself again and swaps in the new content, so nobody has to press refresh.
 * It only reads counts and dates; no names, emails or finance data leave this endpoint.
 */
class LiveController extends Controller
{
    public function state(): JsonResponse
    {
        $requests = AccessRequest::where('status', AccessRequest::PENDING)->count();
        $accounts = User::where('status', User::STATUS_PENDING)->count();

        $stamp = md5(json_encode([
            ActivityLog::max('id'),
            AccessRequest::count(),
            AccessRequest::max('updated_at'),
            $requests,
            User::count(),
            User::max('updated_at'),
            $accounts,
        ]));

        return response()->json([
            'stamp'    => $stamp,
            'requests' => $requests,
            'accounts' => $accounts,
        ])->header('Cache-Control', 'no-store');
    }
}
