<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Update the user's preferences.
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email_orders'  => ['boolean'],
            'email_tickets' => ['boolean'],
            'email_reports' => ['boolean'],
            'sms_alerts'    => ['boolean'],
            'language'      => ['nullable', 'string', 'max:50'],
            'timezone'      => ['nullable', 'string', 'max:50'],
        ]);

        $user = $request->user();
        $prefs = $user->preferences ?? [];
        
        $user->preferences = array_merge($prefs, $validated);
        $user->save();

        return Redirect::back();
    }

    /**
     * Request to enable 2FA (Returns Secret & QR).
     */
    public function requestTwoFactorAuth(Request $request)
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();
        
        // Store in session temporarily
        $request->session()->put('2fa_secret', $secret);

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            $request->user()->email,
            $secret
        );

        $renderer = new ImageRenderer(
            new RendererStyle(256),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $qrCodeSvg = $writer->writeString($qrCodeUrl);

        return response()->json([
            'qrCodeSvg' => $qrCodeSvg,
            'secret'    => $secret,
        ]);
    }

    /**
     * Confirm and Enable 2FA.
     */
    public function confirmTwoFactorAuth(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $secret = $request->session()->get('2fa_secret');
        if (!$secret) {
            return response()->json(['message' => 'Session expired. Try again.'], 400);
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($secret, $request->code);

        if ($valid) {
            $user = $request->user();
            $user->google2fa_secret = $secret;
            $user->save();

            $request->session()->forget('2fa_secret');
            $request->session()->put('2fa_verified', true);
            return response()->json(['message' => '2FA Enabled successfully!']);
        }

        return response()->json([
            'errors' => ['code' => ['Invalid 2FA code. Please try again.']]
        ], 422);
    }

    /**
     * Disable 2FA.
     */
    public function disableTwoFactorAuth(Request $request)
    {
        $user = $request->user();
        $user->google2fa_secret = null;
        $user->save();

        return Redirect::back();
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
