<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Franchisee;
use App\Models\State;
use App\Models\District;
use App\Models\City;
use App\Models\User;
use App\Http\Resources\FranchiseeResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FranchiseeController extends Controller
{
    /**
     * List all franchisees with filtering and search.
     * Scoped by user role: Admin sees all, State Head sees their state, etc.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $franchisees = Franchisee::with(['state', 'district', 'city', 'districtHead'])
            ->when($request->search, function ($query, $search) {
                $search = trim($search);
                $query->where(function ($q) use ($search) {
                    $q->where('shop_name', 'like', "%{$search}%")
                      ->orWhere('owner_name', 'like', "%{$search}%")
                      ->orWhere('mobile', 'like', "%{$search}%")
                      ->orWhere('shop_code', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->state_id, function ($query, $stateId) {
                $query->where('state_id', $stateId);
            })
            ->when($request->district_id, function ($query, $districtId) {
                $query->where('district_id', $districtId);
            })
            // Role-based scoping
            ->when($user->isStateHead(), function ($query) use ($user) {
                $stateIds = $user->assignedStateIds();
                $query->whereIn('state_id', $stateIds);
            })
            ->when($user->isZoneHead() || $user->isDistrictHead(), function ($query) use ($user) {
                $districtIds = $user->assignedDistrictIds();
                $query->whereIn('district_id', $districtIds);
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Network/Franchisees/Index', [
            'franchisees' => FranchiseeResource::collection($franchisees),
            'filters' => $request->only(['search', 'status', 'state_id', 'district_id']),
            'states' => State::orderBy('name')->get(['id', 'name']),
            'statusOptions' => ['enquiry', 'registered', 'approved', 'rejected', 'active', 'suspended', 'banned'],
        ]);
    }

    /**
     * Show the registration form for a new franchisee.
     */
    public function create()
    {
        return Inertia::render('Network/Franchisees/CreateEdit', [
            'states' => State::orderBy('name')->get(['id', 'name']),
            'districts' => District::orderBy('name')->get(['id', 'name', 'state_id']),
            'cities' => City::orderBy('name')->get(['id', 'name', 'district_id']),
        ]);
    }

    /**
     * Store a new franchisee registration.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            // Required
            'shop_name' => 'required|string|max:255',
            'owner_name' => 'required|string|max:255',
            'mobile' => 'required|string|max:15|regex:/^([0-9\s\-\+\(\)]*)$/',
            'state_id' => 'required|exists:states,id',
            'district_id' => 'required|exists:districts,id',
            // Optional
            'shop_type' => 'in:franchise,distributor,sub_distributor',
            'owner_title' => 'in:Mr,Mrs,Ms,Dr',
            'partner_name' => 'nullable|string|max:255',
            'partner_title' => 'nullable|in:Mr,Mrs,Ms,Dr',
            'owner_dob' => 'nullable|date',
            'education' => 'nullable|string|max:255',
            'occupation' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'whatsapp' => 'nullable|string|max:15|regex:/^([0-9\s\-\+\(\)]*)$/',
            'alternate_phone' => 'nullable|string|max:15|regex:/^([0-9\s\-\+\(\)]*)$/',
            'address' => 'nullable|string',
            'city_id' => 'nullable|exists:cities,id',
            'other_city' => 'nullable|string|max:255',
            'pincode' => 'nullable|string|max:10',
            'residence_address' => 'nullable|string',
            'gst_number' => 'nullable|string|max:20',
            'pan_number' => 'nullable|string|max:12',
            'dl_number_20b' => 'nullable|string|max:255',
            'dl_number_21b' => 'nullable|string|max:255',
            'dl_number_third' => 'nullable|string|max:255',
            'fssai_number' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_holder' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'bank_ifsc' => 'nullable|string|max:15',
            'bank_branch' => 'nullable|string|max:255',
            'utr_number' => 'nullable|string|max:255',
            'transaction_date' => 'nullable|date',
            'investment_amount' => 'nullable|numeric|min:0',
            'ready_to_invest' => 'boolean',
        ]);

        // String Sanitization / Normalization
        $validated['shop_name'] = trim($validated['shop_name']);
        $validated['owner_name'] = trim($validated['owner_name']);
        if (isset($validated['email'])) $validated['email'] = Str::lower(trim($validated['email']));
        if (isset($validated['gst_number'])) $validated['gst_number'] = Str::upper(trim($validated['gst_number']));
        if (isset($validated['pan_number'])) $validated['pan_number'] = Str::upper(trim($validated['pan_number']));
        if (isset($validated['bank_ifsc'])) $validated['bank_ifsc'] = Str::upper(trim($validated['bank_ifsc']));

        $validated['status'] = 'registered';

        DB::beginTransaction();
        try {
            Franchisee::create($validated);
            DB::commit();
            Log::info("Franchisee Registration Triggered: " . $validated['shop_name']);
            return redirect()->route('admin.franchisees.index')
                ->with('success', 'Franchise Network Node formulated successfully. Awaiting Admin Approval.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Franchisee Creation Failure: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Network Architecture Fault: Could not register franchisee.');
        }
    }

    /**
     * Show franchisee profile / detail page.
     */
    public function show(Franchisee $franchisee)
    {
        $franchisee->load(['state', 'district', 'city', 'districtHead', 'zoneHead', 'stateHead', 'users', 'approvedBy']);

        return Inertia::render('Network/Franchisees/Profile', [
            'franchisee' => new FranchiseeResource($franchisee),
        ]);
    }

    /**
     * Edit form for a franchisee.
     */
    public function edit(Franchisee $franchisee)
    {
        return Inertia::render('Network/Franchisees/CreateEdit', [
            'franchisee' => new FranchiseeResource($franchisee),
            'states' => State::orderBy('name')->get(['id', 'name']),
            'districts' => District::orderBy('name')->get(['id', 'name', 'state_id']),
            'cities' => City::orderBy('name')->get(['id', 'name', 'district_id']),
        ]);
    }

    /**
     * Update franchisee details.
     */
    public function update(Request $request, Franchisee $franchisee)
    {
        $validated = $request->validate([
            'shop_name' => 'required|string|max:255',
            'owner_name' => 'required|string|max:255',
            'mobile' => 'required|string|max:15|regex:/^([0-9\s\-\+\(\)]*)$/',
            'state_id' => 'required|exists:states,id',
            'district_id' => 'required|exists:districts,id',
            'shop_type' => 'in:franchise,distributor,sub_distributor',
            'owner_title' => 'in:Mr,Mrs,Ms,Dr',
            'partner_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'whatsapp' => 'nullable|string|max:15|regex:/^([0-9\s\-\+\(\)]*)$/',
            'address' => 'nullable|string',
            'city_id' => 'nullable|exists:cities,id',
            'pincode' => 'nullable|string|max:10',
            'gst_number' => 'nullable|string|max:20',
            'pan_number' => 'nullable|string|max:12',
            'dl_number_20b' => 'nullable|string|max:255',
            'dl_number_21b' => 'nullable|string|max:255',
            'bank_name' => 'nullable|string|max:255',
            'bank_account_holder' => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:255',
            'bank_ifsc' => 'nullable|string|max:15',
        ]);

        $validated['shop_name'] = trim($validated['shop_name']);
        if (isset($validated['email'])) $validated['email'] = Str::lower(trim($validated['email']));
        if (isset($validated['gst_number'])) $validated['gst_number'] = Str::upper(trim($validated['gst_number']));
        if (isset($validated['pan_number'])) $validated['pan_number'] = Str::upper(trim($validated['pan_number']));
        if (isset($validated['bank_ifsc'])) $validated['bank_ifsc'] = Str::upper(trim($validated['bank_ifsc']));

        DB::beginTransaction();
        try {
            $franchisee->update($validated);
            DB::commit();
            Log::info("Franchisee Update Successful. ID: " . $franchisee->id);
            return redirect()->route('admin.franchisees.index')
                ->with('success', 'Franchise Network Node fully synchronized.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Franchisee Sync Failure: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Update Failed: Database integrity constraint encountered.');
        }
    }

    // ══════════════════════════════════════
    //  APPROVAL WORKFLOW
    // ══════════════════════════════════════

    public function approve(Request $request, Franchisee $franchisee)
    {
        $request->validate([
            'shop_code' => 'nullable|string|max:20|unique:franchisees,shop_code,' . $franchisee->id,
        ]);

        DB::beginTransaction();
        try {
            // Determine shop_code: use provided, or keep existing, or auto-generate GPM
            $shopCode = $request->shop_code
                ? Str::upper(trim($request->shop_code))
                : ($franchisee->shop_code ?: $this->generateGpmCode($franchisee));

            $franchisee->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'shop_code' => $shopCode,
            ]);
            DB::commit();
            Log::info("Franchisee ID {$franchisee->id} approved with shop_code: {$shopCode}");
            return back()->with('success', 'Franchisee approved. Shop code: ' . $shopCode);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Franchisee Auth Failure: ' . $e->getMessage());
            return back()->with('error', 'Approval Processing Error.');
        }
    }

    /**
     * Auto-generate GPM shop code: GP + {state abbreviation} + {4-digit sequence}
     * Example: GPMH0070 (Maharashtra #70), GPKA0015 (Karnataka #15)
     * Legacy format preserved exactly for continuity with official documentation.
     */
    private function generateGpmCode(Franchisee $franchisee): string
    {
        $state = $franchisee->state;

        if (!$state || !$state->abbreviation) {
            // Fallback: use 'XX' if state not set
            $prefix = 'GPXX';
        } else {
            $prefix = 'GP' . $state->abbreviation;
        }

        // Find the highest existing sequence for this state prefix
        $lastCode = Franchisee::where('shop_code', 'like', $prefix . '%')
            ->orderByRaw('CAST(SUBSTRING(shop_code, ' . (strlen($prefix) + 1) . ') AS UNSIGNED) DESC')
            ->value('shop_code');

        if ($lastCode) {
            $lastSeq = (int) substr($lastCode, strlen($prefix));
        } else {
            $lastSeq = 0;
        }

        $nextSeq = $lastSeq + 1;

        return $prefix . str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);
    }

    public function reject(Request $request, Franchisee $franchisee)
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $franchisee->update([
                'status' => 'rejected',
                'rejection_reason' => trim($request->rejection_reason),
            ]);
            DB::commit();
            Log::info("Franchisee ID {$franchisee->id} registration rejected.");
            return back()->with('success', 'Network Hub Registration objectively rejected.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Franchisee Reject Failure: ' . $e->getMessage());
            return back()->with('error', 'Processing Failure.');
        }
    }

    public function activate(Franchisee $franchisee)
    {
        DB::beginTransaction();
        try {
            $franchisee->update([
                'status' => 'active',
                'activated_at' => now(),
            ]);
            DB::commit();
            Log::info("Franchisee ID {$franchisee->id} system access activated.");
            return back()->with('success', 'Franchisee System capabilities initialized.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Activation Protocol failed.');
        }
    }

    public function suspend(Franchisee $franchisee)
    {
        DB::beginTransaction();
        try {
            $franchisee->update([
                'status' => 'suspended',
                'deactivated_at' => now(),
            ]);
            DB::commit();
            Log::info("Franchisee ID {$franchisee->id} system access suspended.");
            return back()->with('success', 'Franchisee System capabilities safely suspended.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Suspension Protocol failed.');
        }
    }
}
