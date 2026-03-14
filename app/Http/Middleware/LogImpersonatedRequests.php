<?php

namespace App\Http\Middleware;

use App\Support\ImpersonationAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogImpersonatedRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $state = $request->session()->get('impersonation');
        if (!$state || !$request->user()) {
            return $response;
        }

        $adminId = (int) ($state['admin_user_id'] ?? 0);
        $impersonatedId = (int) $request->user()->id;

        if ($adminId <= 0 || $impersonatedId <= 0) {
            return $response;
        }

        ImpersonationAudit::record([
            'admin_user_id' => $adminId,
            'impersonated_user_id' => $impersonatedId,
            'action' => 'request',
            'reason' => (string) ($state['reason'] ?? ''),
            'method' => $request->method(),
            'path' => $request->path(),
            'response_status' => $response->getStatusCode(),
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        return $response;
    }
}
