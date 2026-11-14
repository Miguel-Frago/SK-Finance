<?php

namespace App\Http\Controllers;

use App\Mail\SkNotice;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\AccessMailer;
use App\Support\Passwords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * "Forgot password" by email for barangay accounts.
 *  - the answer is ALWAYS the same, so nobody can find out which emails have an account
 *  - only an active, approved account gets a link; the link works once and expires (config auth.passwords.users.expire)
 *  - changing the password signs the account out everywhere else
 * (The Admin's own password is reset on the server with: php artisan sk:reset-admin)
 */
class PasswordResetController extends Controller
{
    private const SAME_ANSWER = 'If that email belongs to an active account, a link to choose a new password was sent to it. It works once and expires in one hour. Check your spam folder too.';

    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);
        $email = mb_strtolower(trim($data['email']));

        $user = $this->activeUser($email);
        $key = 'forgot-link:' . $email;

        // at most 3 links per address per hour, so nobody can fill somebody else's inbox
        if ($user && ! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 3600);

            $token = Password::broker()->createToken($user);
            AccessMailer::send($user->email, new SkNotice(
                'Choose a new SK Finance password',
                'Choose a new password',
                [
                    'Hello ' . $user->name . ',',
                    'Someone asked to reset the password of your SK Finance account. If it was you, use the button below. The link works once and expires in one hour.',
                    'If it was not you, ignore this email: your password stays the same.',
                ],
                null,
                route('password.reset', ['token' => $token, 'email' => $user->email]),
                'Choose a new password',
            ));
        }

        return back()->with('status', self::SAME_ANSWER);
    }

    public function showReset(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email', 'max:190'],
            'password' => ['required', 'confirmed', Passwords::rule()],
        ]);

        $user = $this->activeUser(mb_strtolower(trim($data['email'])));
        $status = $user ? Password::broker()->reset(
            ['email' => $user->email, 'token' => $data['token'], 'password' => $data['password'], 'password_confirmation' => $data['password']],
            function (User $account, string $password) {
                Passwords::set($account, $password, false);
                ActivityLog::record('password_reset', $account, 'Chose a new password with the emailed link');
            },
        ) : null;

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'This link has expired or was already used. Ask for a new one.']);
        }

        return redirect()->route('login')->with('status', 'Your password was changed. Sign in with your new password.');
    }

    private function activeUser(string $email): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)->where('status', User::STATUS_APPROVED)
            ->first();
    }
}
