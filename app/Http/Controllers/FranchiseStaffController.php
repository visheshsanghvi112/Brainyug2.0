<?php

namespace App\Http\Controllers;

use App\Models\Franchisee;
use App\Models\FranchiseeStaff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class FranchiseStaffController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $franchiseeId = $user->getEffectiveFranchiseeId();

        abort_if(!$franchiseeId && !$user->isSuperAdmin(), 403, 'Franchise mapping is required.');

        $staff = FranchiseeStaff::query()
            ->with(['user:id,name,email,username,phone,is_active'])
            ->when(
                !$user->isSuperAdmin(),
                fn ($q) => $q->where('franchisee_id', $franchiseeId),
                fn ($q) => $q->when($request->filled('franchisee_id'), fn ($inner) => $inner->where('franchisee_id', (int) $request->input('franchisee_id')))
            )
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim((string) $request->input('search'));
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Franchise/Staff/Index', [
            'staff' => $staff,
            'filters' => $request->only(['search', 'franchisee_id']),
            'canManage' => (bool) $franchiseeId || $user->isSuperAdmin(),
            'isSuperAdmin' => $user->isSuperAdmin(),
            'selectedFranchiseeId' => $request->filled('franchisee_id') ? (int) $request->input('franchisee_id') : $franchiseeId,
            'franchisees' => $user->isSuperAdmin()
                ? Franchisee::query()->select('id', 'shop_name', 'shop_code')->orderBy('shop_name')->get()
                : [],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $franchiseeId = $user->getEffectiveFranchiseeId();
        $targetFranchiseeId = $franchiseeId ?: (int) $request->input('franchisee_id');

        abort_if(!$targetFranchiseeId && !$user->isSuperAdmin(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'max:80', 'alpha_dash', 'unique:users,username'],
            'phone' => ['nullable', 'string', 'max:20'],
            'designation' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:8'],
            'is_active' => ['sometimes', 'boolean'],
            'franchisee_id' => [$user->isSuperAdmin() && !$franchiseeId ? 'required' : 'nullable', 'integer', 'exists:franchisees,id'],
        ]);

        DB::transaction(function () use ($validated, $targetFranchiseeId) {
            $staffUser = User::create([
                'name' => trim($validated['name']),
                'email' => strtolower(trim($validated['email'])),
                'username' => strtolower(trim($validated['username'])),
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'franchisee_id' => $targetFranchiseeId,
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]);

            $staffUser->syncRoles(['Franchisee']);

            FranchiseeStaff::create([
                'franchisee_id' => $targetFranchiseeId,
                'user_id' => $staffUser->id,
                'designation' => $validated['designation'] ?? null,
                'is_active' => (bool) ($validated['is_active'] ?? true),
            ]);
        });

        return back()->with('success', 'Franchise staff user created.');
    }

    public function update(Request $request, FranchiseeStaff $franchiseStaff)
    {
        $user = $request->user();
        $franchiseeId = $user->getEffectiveFranchiseeId();

        if (!$user->isSuperAdmin() && $franchiseStaff->franchisee_id !== $franchiseeId) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($franchiseStaff->user_id)],
            'username' => ['required', 'string', 'max:80', 'alpha_dash', Rule::unique('users', 'username')->ignore($franchiseStaff->user_id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'designation' => ['nullable', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        DB::transaction(function () use ($franchiseStaff, $validated) {
            $franchiseStaff->update([
                'designation' => $validated['designation'] ?? null,
                'is_active' => (bool) $validated['is_active'],
            ]);

            $payload = [
                'name' => trim($validated['name']),
                'email' => strtolower(trim($validated['email'])),
                'username' => strtolower(trim($validated['username'])),
                'phone' => $validated['phone'] ?? null,
                'is_active' => (bool) $validated['is_active'],
            ];

            if (!empty($validated['password'])) {
                $payload['password'] = Hash::make($validated['password']);
            }

            $franchiseStaff->user()->update($payload);
        });

        return back()->with('success', 'Franchise staff user updated.');
    }

    public function destroy(Request $request, FranchiseeStaff $franchiseStaff)
    {
        $user = $request->user();
        $franchiseeId = $user->getEffectiveFranchiseeId();

        if (!$user->isSuperAdmin() && $franchiseStaff->franchisee_id !== $franchiseeId) {
            abort(403);
        }

        DB::transaction(function () use ($franchiseStaff) {
            $linkedUser = $franchiseStaff->user;
            $franchiseStaff->delete();
            if ($linkedUser) {
                $linkedUser->delete();
            }
        });

        return back()->with('success', 'Franchise staff user removed.');
    }
}
