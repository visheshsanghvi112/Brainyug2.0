<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FranchiseeResource;
use App\Models\District;
use App\Models\Franchisee;
use App\Models\State;
use App\Services\GpmCodeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class FranchiseRegistrationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $franchisees = $this->scopedRegistrations($user)
            ->with(['state', 'district', 'city', 'districtHead'])
            ->withCount('users')
            ->when($request->search, function (Builder $query, string $search) {
                $search = trim($search);

                $query->where(function (Builder $nested) use ($search) {
                    $nested->where('shop_name', 'like', "%{$search}%")
                        ->orWhere('owner_name', 'like', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%")
                        ->orWhere('shop_code', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->status, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($request->state_id, fn (Builder $query, int $stateId) => $query->where('state_id', $stateId))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Network/Franchisees/Index', [
            'franchisees' => FranchiseeResource::collection($franchisees),
            'filters' => $request->only(['search', 'status', 'state_id']),
            'states' => State::query()->orderBy('name')->get(['id', 'name']),
            'statusOptions' => ['enquiry', 'registered'],
            'pageTitle' => 'Franchise Registration Queue',
            'pageDescription' => 'Only fresh applications waiting for review live here. Approve identities into the live network; do not treat this as the operating franchise master.',
            'emptyStateTitle' => 'Registration queue is clear.',
            'emptyStateBody' => 'No pending franchise applications need review right now.',
            'contextMode' => 'queue',
            'indexRoute' => 'admin.franchise-registrations.index',
            'createRoute' => null,
            'allowEdit' => false,
        ]);
    }

    public function show(Franchisee $franchisee): Response
    {
        $franchisee = $this->scopedRegistrations(request()->user())
            ->with(['state', 'district', 'city', 'districtHead', 'zoneHead', 'stateHead', 'users.roles', 'approvedBy'])
            ->whereKey($franchisee->id)
            ->firstOrFail();

        return Inertia::render('Network/Franchisees/Profile', [
            'franchisee' => new FranchiseeResource($franchisee),
            'indexRoute' => 'admin.franchise-registrations.index',
            'approveRoute' => 'admin.franchise-registrations.approve',
            'rejectRoute' => 'admin.franchise-registrations.reject',
            'activateRoute' => 'admin.franchises.activate',
            'suspendRoute' => 'admin.franchises.suspend',
            'provisionRoute' => 'admin.franchises.provision-owner',
            'contextMode' => 'queue',
            'allowEdit' => false,
            'allowProvision' => false,
        ]);
    }

    public function approve(Request $request, Franchisee $franchisee, GpmCodeService $gpmCodeService): RedirectResponse
    {
        $franchisee = $this->scopedRegistrations($request->user())
            ->whereKey($franchisee->id)
            ->firstOrFail();

        $request->validate([
            'shop_code' => 'nullable|string|max:20|unique:franchisees,shop_code,' . $franchisee->id,
        ]);

        DB::transaction(function () use ($request, $franchisee, $gpmCodeService) {
            $franchisee->loadMissing('state');

            $shopCode = $request->filled('shop_code')
                ? Str::upper(trim((string) $request->shop_code))
                : ($franchisee->shop_code ?: $gpmCodeService->nextFor($franchisee));

            $franchisee->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
                'shop_code' => $shopCode,
            ]);
        });

        return back()->with('success', 'Registration approved and shop code locked for provisioning.');
    }

    public function reject(Request $request, Franchisee $franchisee): RedirectResponse
    {
        $franchisee = $this->scopedRegistrations($request->user())
            ->whereKey($franchisee->id)
            ->firstOrFail();

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $franchisee->update([
            'status' => 'rejected',
            'rejection_reason' => trim($validated['rejection_reason']),
        ]);

        return back()->with('success', 'Registration rejected and kept in the review archive.');
    }

    private function scopedRegistrations($user): Builder
    {
        return Franchisee::query()
            ->pending()
            ->when($user->isStateHead(), fn (Builder $query) => $query->whereIn('state_id', $user->assignedStateIds()))
            ->when($user->isRegionalHead(), fn (Builder $query) => $query->whereIn('district_id', $user->assignedDistrictIds()))
            ->when($user->isZonalHead() || $user->isDistrictHead(), fn (Builder $query) => $query->whereIn('district_id', $user->assignedDistrictIds()));
    }
}