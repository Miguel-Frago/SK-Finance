<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login', ['mode' => 'login']);
    }

    /** Same page as the sign-in form: the card simply starts on the "Create account" side. */
    public function showRegister(): View
    {
        return view('auth.login', ['mode' => 'register']);
    }

    /**
     * Step 2 of joining. The person already asked for access (RequestAccessController) and the Admin emailed
     * them a secret code. Here they make their account with that code. The email, barangay and position must be
     * the ones in the approved request, the name is taken from that request (it cannot be typed), and the code works once and expires. The new account then waits as
     * "pending" until the Admin approves it, and cannot sign in before that.
     */
    public function register(Request $request): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $keep = $request->except(['password', 'password_confirmation', 'access_code']);

        $data = $request->validateWithBag('register', [
            'email'       => ['required', 'email', 'max:255'],
            'barangay_id' => ['required', 'integer', 'exists:barangays,id'],
            'role'        => ['required', Rule::in(array_keys(User::SIGNUP_ROLES))],
            'access_code' => ['required', 'string', 'max:40'],
            'password'    => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'barangay_id.required' => 'Please choose your barangay.',
            'barangay_id.exists'   => 'Please choose your barangay.',
            'role.required'        => 'Please choose your position: SK Treasurer or SK Chairman.',
            'role.in'              => 'Please choose your position: SK Treasurer or SK Chairman.',
            'access_code.required' => 'Please type the secret code the Admin emailed you.',
        ], ['access_code' => 'secret code']);

        $approved = AccessRequest::where('email', $data['email'])
            ->where('status', AccessRequest::APPROVED)
            ->latest('id')
            ->first();

        // The same message for "no such request" and "wrong code", so nobody can use this form to find out which emails exist
        if (! $approved || ! $approved->codeMatches($data['access_code'])) {
            return back()->withInput($keep)->withErrors(
                ['access_code' => 'That email and secret code do not match an approved request. Check the email from the Admin, or request access first.'],
                'register'
            );
        }

        if ($approved->code_expires_at && $approved->code_expires_at->isPast()) {
            return back()->withInput($keep)->withErrors(
                ['access_code' => 'This secret code has expired. Ask the Admin to send you a new one.'],
                'register'
            );
        }

        if ((int) $approved->barangay_id !== (int) $data['barangay_id'] || $approved->role !== $data['role']) {
            return back()->withInput($keep)->withErrors(
                ['role' => 'Your barangay and position must be the same as in your approved request (' . $approved->barangay->name . ', ' . $approved->role_label . ').'],
                'register'
            );
        }

        if (User::where('email', $data['email'])->exists()) {
            return back()->withInput($keep)->withErrors(
                ['email' => 'An account with this email already exists. Please sign in.'],
                'register'
            );
        }

        // The name is locked to the one in the approved request: nothing typed in the form can change it
        $user = new User([
            'name'     => $approved->full_name,
            'email'    => $data['email'],
            'password' => $data['password'], // hashed by the User model
        ]);
        // set directly: none of these can be mass-assigned
        $user->role              = $approved->role;
        $user->barangay_id       = $approved->barangay_id;
        $user->status            = User::STATUS_PENDING;
        $user->access_request_id = $approved->id;
        $user->save();

        // the code is used up
        $approved->forceFill(['status' => AccessRequest::REGISTERED, 'code_used_at' => now()])->save();

        ActivityLog::record(
            'registered',
            $approved,
            "{$user->name} created an account as {$approved->role_label} of {$approved->barangay->name} and is waiting for approval"
        );

        return redirect()->route('login')->with(
            'status',
            "Account created for {$user->name}. The Admin will check your details and email you once it is approved. You can sign in after that."
        );
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Throttle by email + IP: 5 attempts per minute
        $throttleKey = Str::lower($credentials['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => "Too many login attempts. Try again in {$seconds} seconds."]);
        }

        // Only approved accounts that are switched on can sign in
        if (Auth::attempt($credentials + ['is_active' => true, 'status' => User::STATUS_APPROVED], $request->boolean('remember'))) {
            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();

            ActivityLog::record('login', null, 'Signed in');

            return redirect()->intended(route('dashboard'));
        }

        RateLimiter::hit($throttleKey, 60);

        // Right password but the account cannot sign in yet (or any more): say why, instead of "credentials do not match"
        $known = User::where('email', Str::lower($credentials['email']))->first();

        if ($known && Hash::check($credentials['password'], $known->password)) {
            if (! $known->isApproved()) {
                return back()
                    ->withInput($request->only('email'))
                    ->withErrors(['email' => 'Your account is waiting for the Admin\'s approval. You will get an email when it is approved.']);
            }

            if (! $known->isActive()) {
                return back()
                    ->withInput($request->only('email'))
                    ->withErrors(['email' => 'This account has been switched off. Please contact the SK Federation.']);
            }
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'These credentials do not match our records.']);
    }

    public function logout(Request $request): RedirectResponse
    {
        ActivityLog::record('logout', null, 'Signed out');

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
