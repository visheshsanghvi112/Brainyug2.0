<?php

namespace App\Http\Middleware;

use App\Support\ErpModuleAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(403);
        }

        // Super Admin remains recoverable even if role/user matrix is misconfigured.
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $routeName = (string) ($request->route()?->getName() ?? '');
        $requirement = ErpModuleAccess::requiredForRoute($routeName, $request->method());

        if ($requirement === null) {
            if (ErpModuleAccess::isProtectedRouteName($routeName)) {
                Log::warning('Module access route is protected but unmapped', [
                    'user_id' => $user->id,
                    'route' => $routeName,
                    'method' => $request->method(),
                    'path' => $request->path(),
                ]);

                if ((bool) config('erp.module_access.strict_unmapped', false)) {
                    abort(403, 'Module policy denied access to an unmapped protected route.');
                }
            }

            return $next($request);
        }

        if (!ErpModuleAccess::can($user, $requirement['module'], $requirement['action'])) {
            Log::notice('Module access denied', [
                'user_id' => $user->id,
                'route' => $routeName,
                'method' => $request->method(),
                'module' => $requirement['module'],
                'action' => $requirement['action'],
            ]);

            abort(403, 'You do not have permission to access this module action.');
        }

        return $next($request);
    }
}
