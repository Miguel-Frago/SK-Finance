<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\AccessQr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Sign in by scanning the QR code on the SK Access ID, then typing the 6-digit PIN.
 * The page looks the same whether the card is valid or not, so it never reveals anything by itself.
 */
class QrLoginController extends Controller
{
    private const LOCK_SECONDS = 900; // 15 minutes

    public function show(Request $request, string $token): View
    {
        // Scanning the card ALWAYS asks for the PIN, even in a browser that is already signed in
        // (or has "Keep me signed in"). So any existing session on this device is closed first.
        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return view('auth.qr', ['token' => $token]);
    }

    public function login(Request $request, string $token): RedirectResponse
    {
        $request->validate(['pin' => ['required', 'digits:6']], [
            'pin.required' => 'Please enter your 6-digit PIN.',
            'pin.digits'   => 'Your PIN is exactly 6 digits.',
        ]);

        $user    = User::where('qr_token_hash', AccessQr::hash($token))->first();
        $ipKey   = 'qr-ip|' . $request->ip();
        $userKey = $user ? 'qr-user|' . $user->id : null;

        // 5 wrong PINs on one card, or 10 wrong tries from one device: locked for 15 minutes
        if (RateLimiter::tooManyAttempts($ipKey, 10) || ($userKey && RateLimiter::tooManyAttempts($userKey, 5))) {
            $seconds = max(
                RateLimiter::availableIn($ipKey),
                $userKey ? RateLimiter::availableIn($userKey) : 0
            );

            return back()->withErrors(['pin' => 'Too many wrong tries. Try again in ' . (int) ceil($seconds / 60) . ' minute(s).']);
        }

        if (! $user || ! $user->canSignIn() || ! filled($user->qr_pin) || ! Hash::check((string) $request->input('pin'), $user->qr_pin)) {
            RateLimiter::hit($ipKey, self::LOCK_SECONDS);
            if ($userKey) {
                RateLimiter::hit($userKey, self::LOCK_SECONDS);
            }

            return back()->withErrors(['pin' => 'This Access ID or PIN is not valid.']);
        }

        RateLimiter::clear($ipKey);
        RateLimiter::clear($userKey);

        Auth::login($user);
        $request->session()->regenerate();

        ActivityLog::record('login', null, 'Signed in with the QR Access ID');

        return redirect()->route('dashboard');
    }
}
