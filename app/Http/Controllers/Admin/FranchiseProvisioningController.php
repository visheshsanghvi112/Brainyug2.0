<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Franchisee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FranchiseProvisioningController extends Controller
{
    public function store(Request $request, Franchisee $franchisee): RedirectResponse
    {
        abort_if(!in_array($franchisee->status, ['approved', 'active'], true), 422, 'Franchise must be approved before owner provisioning.');
        abort_if(blank($franchisee->shop_code), 422, 'Franchise must have a shop code before owner provisioning.');
        abort_if($franchisee->users()->exists(), 422, 'An ERP owner account is already linked to this franchise.');

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['nullable', 'string', 'max:255', 'alpha_dash', 'unique:users,username'],
            'phone' => ['nullable', 'string', 'max:15', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        DB::transaction(function () use ($validated, $request, $franchisee) {
            $user = User::create([
                'name' => trim($validated['name'] ?? $franchisee->owner_name),
                'username' => Str::lower(trim($validated['username'] ?? $franchisee->shop_code)),
                'email' => Str::lower(trim($validated['email'])),
                'phone' => $validated['phone'] ?? $franchisee->mobile,
                'password' => Hash::make($validated['password']),
                'franchisee_id' => $franchisee->id,
                'is_active' => true,
                'parent_id' => $request->user()->id,
            ]);

            $user->assignRole('Franchisee');
        });

        return back()->with('success', 'Franchise owner account provisioned and linked to the approved franchise.');
    }
}