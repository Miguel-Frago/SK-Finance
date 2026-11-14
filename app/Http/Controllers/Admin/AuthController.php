<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** The Admin (SK Federation President) signs in here, on a page of their own, with their own guard. */
class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = 'admin|' . Str::lower($credentials['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => "Too many sign-in attempts. Try again in {$seconds} seconds."]);
        }

        if (Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();

            $admin = Auth::guard('admin')->user();

            // Lets the app end this session if the Admin password is changed or reset somewhere else
            $request->session()->put('admin_pw', sha1($admin->password));

            ActivityLog::record('login', null, 'Admin signed in');

            if ($admin->must_change_password) {
                return redirect()->route('admin.profile')->with('warning', 'Welcome! Please choose your own password before you continue.');
            }

            return redirect()->route('admin.dashboard');
        }

        RateLimiter::hit($throttleKey, 60);

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'These credentials do not match our records.']);
    }

    public function logout(Request $request): RedirectResponse
    {
        ActivityLog::record('logout', null, 'Admin signed out');

        Auth::guard('admin')->logout();

        // Only the admin guard is signed out here; a barangay account in the same browser keeps its own session
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
