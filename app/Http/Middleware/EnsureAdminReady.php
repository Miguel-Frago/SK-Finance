<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * For every Admin page (after sign-in):
 *  - if the Admin password was changed or reset since this browser signed in (for example at a handover),
 *    this session ends, so a former president cannot stay signed in on another device;
 *  - an Admin who has a temporary password can only reach the profile page until they choose their own.
 */
class EnsureAdminReady
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if (! $admin) {
            return $next($request);
        }

        $fingerprint = $request->session()->get('admin_pw');

        if ($fingerprint !== null && ! hash_equals((string) $fingerprint, sha1((string) $admin->password))) {
            Auth::guard('admin')->logout();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->withErrors([
                'email' => 'Your session ended because the Admin password was changed. Please sign in again.',
            ]);
        }

        if ($admin->must_change_password && ! $request->routeIs('admin.profile', 'admin.profile.*', 'admin.logout')) {
            return redirect()->route('admin.profile')->with('warning', 'Please set your own password before you continue.');
        }

        return $next($request);
    }
}
