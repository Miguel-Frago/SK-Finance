<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The signed-in treasurer's own profile: name, email and password.
 * Only ever touches the logged-in user, so there is no user id in the URL.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    /** Change name and email. Changing the email also needs the current password. */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Emails are kept in lower case so "Name@Mail.com" and "name@mail.com" are the same account
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $emailChanged = $request->input('email') !== Str::lower($user->email);

        $data = $request->validateWithBag('profile', [
            'name'             => ['required', 'string', 'max:255'],
            'email'            => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => $emailChanged ? ['required', 'current_password'] : ['nullable'],
        ], [], ['current_password' => 'current password']);

        $user->update([
            'name'  => trim($data['name']),
            'email' => $data['email'],
        ]);

        return redirect()->route('profile.edit')->with('success', 'Your profile was updated.');
    }

    /** Change the password. Needs the current password, and signs this browser in again with a fresh session. */
    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ], [
            'password.different' => 'The new password must be different from the current one.',
        ], ['current_password' => 'current password', 'password' => 'new password']);

        $request->user()->forceFill([
            'password'       => $request->input('password'),   // hashed by the User model
            'remember_token' => Str::random(60),               // "keep me signed in" cookies on other devices stop working
        ])->save();

        $request->session()->regenerate();

        return redirect()->route('profile.edit')->with('success', 'Your password was changed.');
    }
}
