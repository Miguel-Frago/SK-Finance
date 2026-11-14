<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account with a temporary password (given by the Admin) can do nothing but choose its own password, however it signed
 * in (password or QR). Messages and every other page are closed until then.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->routeIs('password.choose', 'password.choose.update', 'logout')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Choose your own password first.'], 423);
            }

            return redirect()->route('password.choose')->with('warning', 'Please choose your own password before you continue.');
        }

        return $next($request);
    }
}
