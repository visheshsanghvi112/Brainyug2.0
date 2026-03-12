<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorIsVerified
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_2fa_enabled && !$request->session()->has('2fa_verified')) {
            // Allow them to visit the 2FA verification page, logout, and manage their 2FA/settings
            $allowedRoutes = [
                '2fa.index',
                '2fa.verify',
                'logout',
                'profile.edit',
                'profile.2fa.disable'
            ];

            if (!in_array($request->route()?->getName(), $allowedRoutes)) {
                return redirect()->route('2fa.index');
            }
        }

        return $next($request);
    }
}
