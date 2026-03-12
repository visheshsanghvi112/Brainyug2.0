<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorAuthController extends Controller
{
    /**
     * Display the 2FA verification prompt.
     */
    public function index(Request $request)
    {
        if (!$request->user() || !$request->user()->is_2fa_enabled) {
            return redirect()->route('dashboard');
        }

        if ($request->session()->has('2fa_verified')) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/Verify2FA');
    }

    /**
     * Verify the 2FA code from the user.
     */
    public function verify(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (!$user->is_2fa_enabled) {
            return redirect()->route('dashboard');
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($user->google2fa_secret, $request->code);

        if ($valid) {
            $request->session()->put('2fa_verified', true);
            return redirect()->intended(route('dashboard', absolute: false));
        }

        return back()->withErrors(['code' => 'The provided 2FA code was invalid.']);
    }
}
