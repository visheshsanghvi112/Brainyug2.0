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
            'desktop_alerts' => ['boolean'],
            'weekly_digest' => ['boolean'],
            'language'      => ['nullable', 'string', 'max:50'],
            'timezone'      => ['nullable', 'string', 'max:50'],
            // Legacy + modern printer profiles.
            'printer_type' => ['nullable', 'string', 'in:dotMatrix,laserJet,thermal,epson_epos,escpos_network,escpos_usb,browser_print,generic_windows'],
            'printer_connection' => ['nullable', 'string', 'in:network,usb,bluetooth,browser,system_spooler'],
            'printer_paper_width' => ['nullable', 'string', 'in:58mm,72mm,80mm,a4'],
            'printer_ip' => ['nullable', 'string', 'max:100'],
            'printer_port' => ['nullable', 'integer', 'between:1,65535'],
            'printer_name' => ['nullable', 'string', 'max:120'],
            'printer_driver' => ['nullable', 'string', 'in:epson_epos_sdk,escpos_raw,windows_gdi,browser_native'],
            'print_copies' => ['nullable', 'integer', 'between:1,5'],
            'auto_cut_receipt' => ['boolean'],
            'open_cash_drawer' => ['boolean'],
            'epos_timeout_ms' => ['nullable', 'integer', 'between:500,20000'],
            'show_purchase_rate' => ['boolean'],
            'csv_format' => ['nullable', 'string', 'in:marg,acme,medvision'],
            'receipt_layout' => ['nullable', 'string', 'in:thermal,a4'],
            'auto_print_after_checkout' => ['boolean'],
            'auto_open_invoice_after_checkout' => ['boolean'],
            'auto_lock_bill_on_hold' => ['boolean'],
            'smart_batch_suggestion' => ['boolean'],
            'low_stock_daily_digest' => ['boolean'],
        ]);

        $user = $request->user();

        $defaults = [
            'email_orders' => true,
            'email_tickets' => true,
            'email_reports' => false,
            'sms_alerts' => false,
            'desktop_alerts' => true,
            'weekly_digest' => false,
            'language' => 'English',
            'timezone' => 'Asia/Kolkata',
            // Legacy printer migration defaults.
            'printer_type' => 'laserJet',
            'printer_connection' => 'system_spooler',
            'printer_paper_width' => '80mm',
            'printer_ip' => null,
            'printer_port' => 9100,
            'printer_name' => null,
            'printer_driver' => 'browser_native',
            'print_copies' => 1,
            'auto_cut_receipt' => true,
            'open_cash_drawer' => false,
            'epos_timeout_ms' => 5000,
            'show_purchase_rate' => false,
            'csv_format' => 'marg',
            'receipt_layout' => 'thermal',
            'auto_print_after_checkout' => true,
            'auto_open_invoice_after_checkout' => true,
            'auto_lock_bill_on_hold' => false,
            'smart_batch_suggestion' => true,
            'low_stock_daily_digest' => true,
        ];

        $prefs = is_array($user->preferences) ? $user->preferences : [];
        $merged = array_merge($defaults, $prefs, $validated);
        $user->preferences = $this->normalizePrinterPreferences($merged);
        $user->save();

        return Redirect::back();
    }

    /**
     * Keep old printer settings functional while enabling modern profiles.
     */
    private function normalizePrinterPreferences(array $prefs): array
    {
        $type = $prefs['printer_type'] ?? 'laserJet';

        if ($type === 'dotMatrix') {
            $prefs['printer_connection'] = $prefs['printer_connection'] ?? 'system_spooler';
            $prefs['printer_driver'] = $prefs['printer_driver'] ?? 'windows_gdi';
            $prefs['printer_paper_width'] = 'a4';
            $prefs['receipt_layout'] = 'a4';
        } elseif ($type === 'laserJet') {
            $prefs['printer_connection'] = $prefs['printer_connection'] ?? 'system_spooler';
            $prefs['printer_driver'] = $prefs['printer_driver'] ?? 'windows_gdi';
            $prefs['printer_paper_width'] = 'a4';
            $prefs['receipt_layout'] = 'a4';
        } elseif (in_array($type, ['thermal', 'epson_epos', 'escpos_network', 'escpos_usb'], true)) {
            $prefs['receipt_layout'] = 'thermal';
            $prefs['printer_paper_width'] = $prefs['printer_paper_width'] ?? '80mm';
        }

        if ($type === 'epson_epos') {
            $prefs['printer_driver'] = 'epson_epos_sdk';
            $prefs['printer_connection'] = 'network';
        }

        if ($type === 'escpos_network') {
            $prefs['printer_driver'] = 'escpos_raw';
            $prefs['printer_connection'] = 'network';
        }

        if ($type === 'escpos_usb') {
            $prefs['printer_driver'] = 'escpos_raw';
            $prefs['printer_connection'] = 'usb';
        }

        if ($type === 'browser_print') {
            $prefs['printer_driver'] = 'browser_native';
            $prefs['printer_connection'] = 'browser';
        }

        if ($type === 'generic_windows') {
            $prefs['printer_driver'] = 'windows_gdi';
            $prefs['printer_connection'] = 'system_spooler';
        }

        return $prefs;
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
