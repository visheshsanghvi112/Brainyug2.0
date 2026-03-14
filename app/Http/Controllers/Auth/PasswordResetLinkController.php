<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
            'showEmailFallback' => (bool) session('showEmailFallback', false),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->filled('identifier') || $request->filled('password')) {
            return $this->handleDirectReset($request);
        }

        $request->validate([
            'email' => 'required|email',
        ]);

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status == Password::RESET_LINK_SENT) {
            return back()->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }

    private function handleDirectReset(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
        ]);

        $identifier = trim((string) $validated['identifier']);
        $user = $this->findUserByIdentifier($identifier);

        if (!$user) {
            throw ValidationException::withMessages([
                'identifier' => ['Account not found for this user ID.'],
            ]);
        }

        if (!$user->needsPasswordReset()) {
            return back()
                ->withInput($request->except(['password', 'password_confirmation']))
                ->with('showEmailFallback', true)
                ->withErrors([
                    'identifier' => 'First-time direct reset is already completed for this account. Use email reset below.',
                ]);
        }

        if (! $user->is_active && ! $this->canActivateViaFirstReset($user)) {
            throw ValidationException::withMessages([
                'identifier' => ['This account is inactive. Contact support/admin.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
            'is_active' => true,
        ])->save();

        $user->clearMustResetPasswordFlag();

        return redirect()->route('login')->with('status', 'Password set successfully. Please log in.');
    }

    private function findUserByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $lower = Str::lower($identifier);

        $query = User::query()
            ->whereRaw('LOWER(username) = ?', [$lower])
            ->orWhereRaw('LOWER(legacy_username) = ?', [$lower]);

        if (ctype_digit($identifier)) {
            $query->orWhere('legacy_user_id', (int) $identifier)
                ->orWhere('id', (int) $identifier);
        }

        return $query
            ->orderByRaw('CASE WHEN LOWER(username) = ? THEN 0 WHEN LOWER(legacy_username) = ? THEN 1 ELSE 2 END', [$lower, $lower])
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();
    }

    private function canActivateViaFirstReset(User $user): bool
    {
        return $user->needsPasswordReset() && ! empty($user->legacy_source);
    }
}
