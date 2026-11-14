<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use App\Models\ActivityLog;
use App\Models\Barangay;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Step 1 of joining: anyone can ask for access with their email, name, barangay and position.
 * Nothing is created until the Admin approves; the Admin then emails a secret code (AccessRequestController).
 */
class RequestAccessController extends Controller
{
    public function create(): View
    {
        return view('auth.request-access');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email'       => ['required', 'email', 'max:255'],
            'last_name'   => ['required', 'string', 'max:100'],
            'first_name'  => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'barangay_id' => ['required', 'integer', 'exists:barangays,id'],
            'role'        => ['required', Rule::in(array_keys(User::SIGNUP_ROLES))],
        ], [
            'barangay_id.required' => 'Please choose your barangay.',
            'barangay_id.exists'   => 'Please choose your barangay.',
            'role.required'        => 'Please choose your position.',
            'role.in'              => 'Please choose your position.',
        ], ['last_name' => 'last name', 'first_name' => 'first name', 'middle_name' => 'middle name']);

        $barangay = Barangay::findOrFail($data['barangay_id']);
        $label    = User::SIGNUP_ROLES[$data['role']];

        if (User::where('email', $data['email'])->exists()) {
            return back()->withInput()->withErrors(['email' => 'An account with this email already exists. Please sign in instead.']);
        }

        if (AccessRequest::open()->where('email', $data['email'])->exists()) {
            return back()->withInput()->withErrors(['email' => 'You already sent a request with this email. Please wait for the Admin to email you.']);
        }

        if (! User::seatAvailable($barangay->id, $data['role'])) {
            $seats = User::SEATS[$data['role']];

            return back()->withInput()->withErrors([
                'role' => $seats === 1
                    ? "{$barangay->name} already has an active {$label}. The SK Federation must switch the old account off before a new one can join."
                    : "{$barangay->name} already has all {$seats} {$label} places filled. The SK Federation must switch one of those accounts off before a new one can join.",
            ]);
        }

        $accessRequest = new AccessRequest([
            'email'       => $data['email'],
            'last_name'   => trim($data['last_name']),
            'first_name'  => trim($data['first_name']),
            'middle_name' => filled($data['middle_name'] ?? null) ? trim($data['middle_name']) : null,
            'barangay_id' => $barangay->id,
            'role'        => $data['role'],
        ]);
        $accessRequest->status = AccessRequest::PENDING;
        $accessRequest->save();

        ActivityLog::record('requested', $accessRequest, "New access request: {$accessRequest->full_name} as {$label} of {$barangay->name}");

        return redirect()->route('request-access.sent');
    }

    public function sent(): View
    {
        return view('auth.request-sent');
    }
}
