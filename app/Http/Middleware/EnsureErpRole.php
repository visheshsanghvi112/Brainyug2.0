<?php

namespace App\Http\Middleware;

use App\Support\ErpRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureErpRole
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();
        $resolvedRoles = $this->resolveRoles($roles);

        if (!$user || ($resolvedRoles !== [] && !ErpRole::hasAny($user, $resolvedRoles))) {
            abort(403);
        }

        return $next($request);
    }

    private function resolveRoles(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        $resolved = [];

        foreach ($roles as $role) {
            if (!is_string($role)) {
                continue;
            }

            // Support both Laravel's comma-separated middleware params and route strings using pipes.
            $chunks = preg_split('/[|,]/', $role) ?: [];
            foreach ($chunks as $chunk) {
                $normalized = trim($chunk);
                if ($normalized !== '') {
                    $resolved[] = $normalized;
                }
            }
        }

        return array_values(array_unique($resolved));
    }
}