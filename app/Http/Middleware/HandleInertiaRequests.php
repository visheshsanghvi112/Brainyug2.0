<?php

namespace App\Http\Middleware;

use App\Support\ErpModuleAccess;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $supportAccessState = function () use ($request) {
            $state = $request->session()->get('impersonation');
            if (!$state || !$request->user()) {
                return null;
            }

            return [
                'admin_user_id' => (int) ($state['admin_user_id'] ?? 0),
                'admin_user_name' => (string) ($state['admin_user_name'] ?? 'Super Admin'),
                'accessed_user_id' => (int) $request->user()->id,
                'accessed_user_name' => (string) $request->user()->name,
                'reason' => (string) ($state['reason'] ?? ''),
                'started_at' => (string) ($state['started_at'] ?? ''),
                'support_access' => true,
            ];
        };

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'must_reset_password' => $request->user()->needsPasswordReset(),
                    'roles' => $request->user()->getRoleNames(),
                    'permissions' => $request->user()->getAllPermissions()->pluck('name'),
                    'module_access' => ErpModuleAccess::effectiveMatrixFor($request->user()),
                ] : null,
            ],
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'supportAccess' => $supportAccessState,
            // Keep legacy prop name temporarily so existing components keep working.
            'impersonation' => $supportAccessState,
        ];
    }
}
