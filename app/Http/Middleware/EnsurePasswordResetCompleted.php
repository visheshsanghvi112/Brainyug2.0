<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordResetCompleted
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->needsPasswordReset()) {
            return $next($request);
        }

        $allowedRoutes = [
            'password.force.edit',
            'password.force.update',
            'logout',
            '2fa.index',
            '2fa.verify',
            'profile.2fa.disable',
        ];

        if (!in_array($request->route()?->getName(), $allowedRoutes, true)) {
            return redirect()
                ->route('password.force.edit')
                ->with('status', 'For security, please reset your password before continuing.');
        }

        return $next($request);
    }
}
