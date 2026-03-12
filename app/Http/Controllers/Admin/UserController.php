<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Franchisee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Inertia\Inertia;
use Illuminate\Support\Str;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with(['roles', 'franchisee'])
            ->when($request->search, function ($query, $search) {
                // Ensure the search is trim and lowercase-robust at the query layer if needed.
                $search = trim($search);
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('username', 'like', "%{$search}%");
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Master/Users/Index', [
            'users' => $users,
            'roles' => Role::all(['id', 'name']),
            'filters' => $request->only(['search']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Master/Users/CreateEdit', [
            'roles' => Role::all(['id', 'name']),
            'franchisees' => Franchisee::select('id', 'shop_name as name', 'shop_code as franch_id')->get()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|unique:users|alpha_dash', // Strict username formatting
            'email' => 'required|email|unique:users',
            'phone' => 'nullable|string|max:15|regex:/^([0-9\s\-\+\(\)]*)$/',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|exists:roles,name',
            'franchisee_id' => 'nullable|exists:franchisees,id',
            'is_active' => 'boolean'
        ]);

        $validated['username'] = Str::lower(trim($validated['username']));
        $validated['email'] = Str::lower(trim($validated['email']));
        $validated['name'] = trim($validated['name']);

        DB::beginTransaction();
        try {
            $user = User::create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'franchisee_id' => $validated['franchisee_id'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $user->assignRole($validated['role']);
            
            DB::commit();
            Log::info("Identity Master Provisioned Sequence Successful. User ID: {$user->id}");
            return redirect()->route('admin.users.index')->with('success', 'Security Identity provisioned successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('User Provisioning Failure: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Critical System Failure: Unable to finalize Identity bindings.');
        }
    }

    public function edit(User $user)
    {
        return Inertia::render('Master/Users/CreateEdit', [
            'user' => $user->load('roles'),
            'roles' => Role::all(['id', 'name']),
            'franchisees' => Franchisee::select('id', 'shop_name as name', 'shop_code as franch_id')->get()
        ]);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|alpha_dash|unique:users,username,' . $user->id,
            'email' => 'required|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:15|regex:/^([0-9\s\-\+\(\)]*)$/',
            'password' => 'nullable|string|min:8|confirmed',
            'role' => 'required|exists:roles,name',
            'franchisee_id' => 'nullable|exists:franchisees,id',
            'is_active' => 'boolean'
        ]);

        $validated['username'] = Str::lower(trim($validated['username']));
        $validated['email'] = Str::lower(trim($validated['email']));

        // Security check: Don't allow changing role or demoting Super admin casually
        if ($user->hasRole('Super Admin') && $validated['role'] !== 'Super Admin' && User::role('Super Admin')->count() <= 1) {
             return redirect()->back()->with('error', 'Cannot demote the authoritative system master node.');
        }

        DB::beginTransaction();
        try {
            $updateData = [
                'name' => trim($validated['name']),
                'username' => $validated['username'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'franchisee_id' => $validated['franchisee_id'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ];

            if (!empty($validated['password'])) {
                $updateData['password'] = Hash::make($validated['password']);
            }

            $user->update($updateData);

            // Sync Roles gracefully
            $user->syncRoles([$validated['role']]);

            DB::commit();
            return redirect()->route('admin.users.index')->with('success', 'Security Identity synchronizer accepted payload.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('User Synchronization Failure: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Critical System Failure: Active Identity could not be securely rewritten.');
        }
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return redirect()->back()->with('error', 'Integrity Policy: You cannot recursively archive your own active session.');
        }

        if ($user->hasRole('Super Admin') && User::role('Super Admin')->count() <= 1) {
             return redirect()->back()->with('error', 'Cannot purge the final authoritative system master node.');
        }

        DB::beginTransaction();
        try {
            // Usually, checking relationships here (like if user has transactions).
            // Soft delete keeps it safe.
            $user->delete();
            DB::commit();
            return redirect()->back()->with('success', 'Identity permanently archived from active operations.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('User Archive Failure: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Archive Failure: Database integrity locked. See logs.');
        }
    }
}
