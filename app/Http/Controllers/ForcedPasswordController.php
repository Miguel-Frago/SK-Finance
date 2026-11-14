<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\Passwords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/** The page an account with a temporary password lands on: choose your own password. */
class ForcedPasswordController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect()->route('dashboard');
        }

        return view('auth.choose-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->must_change_password, 404);

        $data = $request->validate([
            'password' => ['required', 'confirmed', Passwords::rule(), function ($attribute, $value, $fail) use ($user) {
                if (Hash::check($value, $user->password)) {
                    $fail('Choose a password that is different from the temporary one.');
                }
            }],
        ]);

        Passwords::set($user, $data['password'], false);
        Passwords::endOtherSessions($user, $request->session()->getId());
        $request->session()->regenerate();

        ActivityLog::record('password_changed', $user, 'Chose their own password (after a temporary one)');

        return redirect()->route('dashboard')->with('success', 'Your password is set. Welcome!');
    }
}
