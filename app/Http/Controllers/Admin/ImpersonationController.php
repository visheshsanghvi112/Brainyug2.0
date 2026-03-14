<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ImpersonationAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function start(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        if (!$admin || !$admin->isSuperAdmin()) {
            abort(403, 'Only Super Admin can start support access sessions.');
        }

        if ($request->session()->has('impersonation')) {
            return back()->with('error', 'Finish the active support access session first.');
        }

        if ((int) $admin->id === (int) $user->id) {
            return back()->with('error', 'You are already logged in as this user.');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        if (!$user->is_active) {
            return back()->with('error', 'Cannot start support access for an inactive user.');
        }

        $state = [
            'admin_user_id' => $admin->id,
            'admin_user_name' => $admin->name,
            'impersonated_user_id' => $user->id,
            'reason' => trim($validated['reason']),
            'started_at' => now()->toIso8601String(),
        ];

        $request->session()->put('impersonation', $state);

        ImpersonationAudit::record([
            'admin_user_id' => $admin->id,
            'impersonated_user_id' => $user->id,
            'action' => 'start',
            'reason' => $state['reason'],
            'method' => $request->method(),
            'path' => $request->path(),
            'response_status' => 302,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Support access session started successfully.');
    }

    public function stop(Request $request): RedirectResponse
    {
        $state = $request->session()->get('impersonation');

        if (!$state) {
            return redirect()->route('dashboard')->with('error', 'No active support access session found.');
        }

        $adminId = (int) ($state['admin_user_id'] ?? 0);
        $impersonatedId = (int) ($state['impersonated_user_id'] ?? 0);
        $reason = (string) ($state['reason'] ?? '');

        ImpersonationAudit::record([
            'admin_user_id' => $adminId,
            'impersonated_user_id' => $impersonatedId,
            'action' => 'stop',
            'reason' => $reason,
            'method' => $request->method(),
            'path' => $request->path(),
            'response_status' => 302,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);

        $request->session()->forget('impersonation');

        $admin = User::find($adminId);
        if (!$admin || !$admin->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Original admin account is unavailable. Please sign in again.');
        }

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('admin.users.index')->with('success', 'Support access session ended. Returned to your admin session.');
    }
}
