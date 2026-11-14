<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\SkNotice;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Support\AccessMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The Admin manages their own account here: name and email, password, and the handover to a new president.
 * Nothing the Admin did (requests, approvals, accounts, the activity log) is tied to the person, so it all stays.
 */
class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('admin.profile', ['admin' => Auth::guard('admin')->user()]);
    }

    public function updateDetails(Request $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();

        $data = $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'email'            => ['required', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($admin->id)],
            'current_password' => ['required', 'string'],
        ], [], ['current_password' => 'current password']);

        if (! Hash::check($data['current_password'], $admin->password)) {
            return back()->withInput($request->except('current_password'))
                ->withErrors(['current_password' => 'The current password is not correct.']);
        }

        ActivityLog::record('updated', null, 'Admin updated their name or email');

        $admin->name  = trim($data['name']);
        $admin->email = Str::lower(trim($data['email']));
        $admin->save();

        return redirect()->route('admin.profile')->with('success', 'Your details were saved.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $admin  = Auth::guard('admin')->user();
        $forced = (bool) $admin->must_change_password;

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [], ['current_password' => 'current password']);

        if (! Hash::check($data['current_password'], $admin->password)) {
            return back()->withErrors(['current_password' => 'The current password is not correct.']);
        }

        if (Hash::check($data['password'], $admin->password)) {
            return back()->withErrors(['password' => 'Please choose a password that is different from the current one.']);
        }

        $admin->password             = $data['password'];   // hashed by the model
        $admin->must_change_password = false;
        $admin->password_changed_at  = now();
        $admin->setRememberToken(Str::random(60));
        $admin->save();

        // this browser stays signed in; every other session of this Admin ends at its next click
        $request->session()->put('admin_pw', sha1($admin->password));

        ActivityLog::record('updated', null, 'Admin changed their password');

        return $forced
            ? redirect()->route('admin.dashboard')->with('success', 'Your password was saved. Welcome!')
            : redirect()->route('admin.profile')->with('success', 'Your password was changed.');
    }

    /**
     * Sends a short email to the Admin's own address, so the Gmail setup can be checked without approving a real
     * request. With MAIL_MAILER=log nothing leaves the server (the message is only written to the log file), and
     * the Admin is told so instead of being told it worked.
     */
    public function testEmail(Request $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return redirect()->route('admin.profile')->with('warning',
                'Emails are not being sent: MAIL_MAILER is set to "' . config('mail.default') . '", which only writes them to the log file. Set it to smtp in the .env file.');
        }

        $sent = AccessMailer::send($admin->email, new SkNotice(
            'SK Finance test email',
            'Your email setup works',
            [
                "Hello {$admin->name},",
                'This is a test message from SK Finance. If you can read it, approvals, messages and secret codes will reach the people you send them to.',
            ]
        ));

        return redirect()->route('admin.profile')->with(
            $sent ? 'success' : 'warning',
            $sent
                ? "Test email sent to {$admin->email}. Check the inbox (and the spam folder)."
                : 'The test email could not be sent. Check the MAIL_ settings in the .env file, then try again.'
        );
    }

    /**
     * The president is being replaced. The account stays (so every request, account and log line stays), but it is
     * reset to the incoming president's name and email with a RANDOM temporary password, which must be replaced at
     * their first sign-in. The outgoing president is signed out everywhere.
     */
    public function handover(Request $request): RedirectResponse
    {
        $admin = Auth::guard('admin')->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_email'        => ['required', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($admin->id)],
            'new_name'         => ['nullable', 'string', 'max:255'],
        ], [
            'new_email.required' => 'Please type the email of the new president.',
        ], ['current_password' => 'current password', 'new_email' => 'new email']);

        if (! Hash::check($data['current_password'], $admin->password)) {
            return back()->withInput($request->except('current_password'))
                ->withErrors(['current_password' => 'The current password is not correct.']);
        }

        $newName  = filled($data['new_name'] ?? null) ? trim($data['new_name']) : 'SK Federation President';
        $newEmail = Str::lower(trim($data['new_email']));
        $temp     = Admin::temporaryPassword();

        // written first, so the line carries the name of the president who handed over
        ActivityLog::record('handover', null, "Admin account handed over from {$admin->name} ({$admin->email}) to {$newName} ({$newEmail})");

        $admin->name                 = $newName;
        $admin->email                = $newEmail;
        $admin->password             = $temp;   // hashed by the model
        $admin->must_change_password = true;
        $admin->password_changed_at  = null;
        $admin->setRememberToken(Str::random(60));
        $admin->save();

        $emailed = false;

        if ($request->boolean('email_it')) {
            $emailed = AccessMailer::send($newEmail, new SkNotice(
                'Your SK Finance Admin account',
                'You are now the SK Finance Admin',
                [
                    "Hello {$newName},",
                    'You are the new Admin of SK Finance for the SK Federation of Luna. Sign in with this email and the temporary password below. You will be asked to choose your own password right away.',
                ],
                $temp,
                route('admin.login'),
                'Sign in as Admin'
            ));
        }

        Auth::guard('admin')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('handover', [
            'email'   => $newEmail,
            'emailed' => $emailed,
            // shown once, only when it was not (or could not be) emailed
            'temp'    => $emailed ? null : $temp,
        ]);
    }
}
