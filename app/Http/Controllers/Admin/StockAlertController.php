<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Franchisee;
use App\Models\StockAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockAlertController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $query = StockAlert::query()
            ->with([
                'product:id,product_name,sku',
                'franchisee:id,shop_name,shop_code',
                'acknowledgedBy:id,name',
            ]);

        $this->applyScope($query, $user);

        $query->when($request->filled('search'), function (Builder $builder) use ($request) {
            $search = trim((string) $request->input('search'));
            $builder->where(function (Builder $nested) use ($search) {
                $nested->where('batch_no', 'like', "%{$search}%")
                    ->orWhere('trigger_source', 'like', "%{$search}%")
                    ->orWhereHas('product', fn (Builder $product) => $product
                        ->where('product_name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                    )
                    ->orWhereHas('franchisee', fn (Builder $franchisee) => $franchisee
                        ->where('shop_name', 'like', "%{$search}%")
                        ->orWhere('shop_code', 'like', "%{$search}%")
                    );
            });
        });

        $query->when($request->filled('status'), fn (Builder $builder) => $builder->where('status', (string) $request->input('status')));
        $query->when($request->filled('alert_level'), fn (Builder $builder) => $builder->where('alert_level', (string) $request->input('alert_level')));
        $query->when($request->filled('alert_type'), fn (Builder $builder) => $builder->where('alert_type', (string) $request->input('alert_type')));

        if ($request->filled('location')) {
            $location = (string) $request->input('location');
            if ($location === 'ho') {
                $query->whereNull('franchisee_id');
            }
            if ($location === 'franchisee') {
                $query->whereNotNull('franchisee_id');
            }
        }

        $query->when($request->filled('from'), fn (Builder $builder) => $builder->whereDate('triggered_at', '>=', (string) $request->input('from')));
        $query->when($request->filled('to'), fn (Builder $builder) => $builder->whereDate('triggered_at', '<=', (string) $request->input('to')));

        $query->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'acknowledged' THEN 1 WHEN 'resolved' THEN 2 ELSE 3 END")
            ->orderByDesc('triggered_at');

        $alerts = $query->paginate(25)->withQueryString();

        $summaryQuery = StockAlert::query();
        $this->applyScope($summaryQuery, $user);

        return Inertia::render('Admin/StockAlerts/Index', [
            'alerts' => $alerts,
            'filters' => $request->only(['search', 'status', 'alert_level', 'alert_type', 'location', 'from', 'to']),
            'statusOptions' => ['pending', 'acknowledged', 'resolved', 'false_alarm'],
            'levelOptions' => ['critical', 'warning', 'info'],
            'typeOptions' => ['threshold', 'expiry', 'variance', 'min_stock', 'overstock'],
            'summary' => [
                'total' => (clone $summaryQuery)->count(),
                'pending' => (clone $summaryQuery)->where('status', 'pending')->count(),
                'critical_pending' => (clone $summaryQuery)->where('status', 'pending')->where('alert_level', 'critical')->count(),
            ],
        ]);
    }

    public function acknowledge(Request $request, StockAlert $stockAlert): RedirectResponse
    {
        $request->validate([
            'status' => 'nullable|in:acknowledged,resolved,false_alarm',
            'action_taken' => 'nullable|string|max:500',
        ]);

        $this->authorizeAlert($request->user(), $stockAlert);

        $status = (string) $request->input('status', 'acknowledged');

        $stockAlert->status = $status;
        $stockAlert->acknowledged_by = $request->user()->id;
        $stockAlert->acknowledged_at = now();

        if ($request->filled('action_taken')) {
            $stockAlert->action_taken = (string) $request->input('action_taken');
        }

        $stockAlert->save();

        return back()->with('success', 'Stock alert updated successfully.');
    }

    private function applyScope(Builder $query, User $user): void
    {
        if ($user->isSuperAdmin() || $user->isAdmin() || $user->isDistributer() || $user->isAccount()) {
            return;
        }

        if ($user->isStateHead()) {
            $ids = Franchisee::query()->whereIn('state_id', $user->assignedStateIds())->pluck('id')->all();
            $query->where(function (Builder $nested) use ($ids) {
                $nested->whereIn('franchisee_id', $ids)->orWhereNull('franchisee_id');
            });
            return;
        }

        if ($user->isRegionalHead()) {
            $ids = Franchisee::query()->whereIn('district_id', $user->assignedDistrictIds())->pluck('id')->all();
            $query->where(function (Builder $nested) use ($ids) {
                $nested->whereIn('franchisee_id', $ids)->orWhereNull('franchisee_id');
            });
            return;
        }

        if ($user->isZoneHead()) {
            $ids = Franchisee::query()->where('zone_head_id', $user->id)->pluck('id')->all();
            $query->where(function (Builder $nested) use ($ids) {
                $nested->whereIn('franchisee_id', $ids)->orWhereNull('franchisee_id');
            });
            return;
        }

        if ($user->isDistrictHead()) {
            $ids = Franchisee::query()->where('district_head_id', $user->id)->pluck('id')->all();
            $query->where(function (Builder $nested) use ($ids) {
                $nested->whereIn('franchisee_id', $ids)->orWhereNull('franchisee_id');
            });
            return;
        }

        $franchiseeId = $user->getEffectiveFranchiseeId();
        if ($franchiseeId) {
            $query->where('franchisee_id', $franchiseeId);
            return;
        }

        $query->whereRaw('1 = 0');
    }

    private function authorizeAlert(User $user, StockAlert $alert): void
    {
        $scopeCheck = StockAlert::query()->whereKey($alert->id);
        $this->applyScope($scopeCheck, $user);

        if (!$scopeCheck->exists()) {
            abort(403, 'You are not allowed to update this alert.');
        }
    }
}
