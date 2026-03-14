<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $login = trim((string) $this->input('email'));
        $password = (string) $this->input('password');
        $remember = $this->boolean('remember');

        $attempts = filter_var($login, FILTER_VALIDATE_EMAIL)
            ? [
                ['email' => Str::lower($login), 'password' => $password],
                ['username' => $login, 'password' => $password],
            ]
            : [
                ['username' => $login, 'password' => $password],
                ['email' => Str::lower($login), 'password' => $password],
            ];

        foreach ($attempts as $credentials) {
            try {
                if (Auth::attempt($credentials, $remember)) {
                    $this->ensureAuthenticatedUserIsActive();
                    RateLimiter::clear($this->throttleKey());

                    return;
                }
            } catch (RuntimeException $e) {
                // When legacy hashes are present, Laravel throws before returning false.
                if (! str_contains($e->getMessage(), 'Bcrypt algorithm')) {
                    throw $e;
                }

                if ($this->attemptLegacyAuthentication($credentials, $remember)) {
                    $this->ensureAuthenticatedUserIsActive();
                    RateLimiter::clear($this->throttleKey());

                    return;
                }
            }
        }

        $inactiveUser = $this->findUserForLogin($login);
        if ($inactiveUser && ! $inactiveUser->is_active) {
            throw ValidationException::withMessages([
                'email' => 'This account is inactive. Contact support/admin.',
            ]);
        }

        if (! Auth::check()) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower(trim((string) $this->input('email'))).'|'.$this->ip());
    }

    private function attemptLegacyAuthentication(array $credentials, bool $remember): bool
    {
        $plainPassword = (string) ($credentials['password'] ?? '');
        if ($plainPassword === '') {
            return false;
        }

        $identifierField = isset($credentials['username']) ? 'username' : 'email';
        $identifierValue = (string) ($credentials[$identifierField] ?? '');
        if ($identifierValue === '') {
            return false;
        }

        $user = User::query()->where($identifierField, $identifierValue)->first();
        if (! $user || ! $user->is_active) {
            return false;
        }

        $storedPassword = (string) $user->getAuthPassword();
        if (! $this->matchesLegacyPassword($plainPassword, $storedPassword)) {
            return false;
        }

        $user->forceFill([
            'password' => Hash::make($plainPassword),
        ])->save();

        Auth::login($user, $remember);

        return true;
    }

    private function matchesLegacyPassword(string $plainPassword, string $storedPassword): bool
    {
        if ($storedPassword === '') {
            return false;
        }

        if (
            str_starts_with($storedPassword, '$2a$') ||
            str_starts_with($storedPassword, '$2x$') ||
            str_starts_with($storedPassword, '$2y$') ||
            str_starts_with($storedPassword, '$2b$')
        ) {
            return password_verify($plainPassword, $storedPassword);
        }

        if ($storedPassword === $plainPassword) {
            return true;
        }

        if (hash_equals(strtolower($storedPassword), md5($plainPassword))) {
            return true;
        }

        if (hash_equals(strtolower($storedPassword), sha1($plainPassword))) {
            return true;
        }

        return false;
    }

    private function findUserForLogin(string $login): ?User
    {
        if ($login === '') {
            return null;
        }

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            return User::query()
                ->where('email', Str::lower($login))
                ->orWhere('username', $login)
                ->first();
        }

        return User::query()
            ->where('username', $login)
            ->orWhere('email', Str::lower($login))
            ->first();
    }

    private function ensureAuthenticatedUserIsActive(): void
    {
        $user = Auth::user();

        if ($user && ! $user->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'This account is inactive. Contact support/admin.',
            ]);
        }
    }
}
