<?php

namespace App\Http\Controllers;

use App\Models\ShopVisitAudit;
use App\Models\Franchisee;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShopVisitController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = ShopVisitAudit::with([
            'franchisee:id,shop_name,shop_code,district_id',
            'auditor:id,name',
        ])->latest('visit_date');

        // Franchisee sees only audits of their own shop
        if ($user->franchisee_id) {
            $query->where('franchisee_id', $user->franchisee_id);
        } elseif ($user->hasRole(['Super Admin', 'State Head'])) {
            // Sees all
        } elseif ($user->isRegionalHead() || $user->isZonalHead() || $user->isDistrictHead()) {
            // Sees only franchisees they manage (via auditor responsibility)
            $query->where('auditor_id', $user->id);
        } else {
            abort(403);
        }

        if ($request->filled('franchisee_id')) {
            $query->where('franchisee_id', $request->franchisee_id);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('visit_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('visit_date', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $query->whereHas('franchisee', function ($q) use ($request) {
                $q->where('shop_name', 'like', '%' . $request->search . '%')
                  ->orWhere('shop_code', 'like', '%' . $request->search . '%');
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $franchisees = ($user->isAdmin() || $user->isStateHead())
            ? Franchisee::select('id', 'shop_name', 'shop_code')->where('status', 'active')->get()
            : collect();

        return Inertia::render('ShopVisits/Index', [
            'visits'      => $query->paginate(20)->withQueryString(),
            'filters'     => $request->only(['franchisee_id', 'date_from', 'date_to', 'search', 'status']),
            'franchisees' => $franchisees,
            'canCreate'   => !$user->franchisee_id,
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        if ($user->franchisee_id) abort(403, 'Only territory heads can log shop visits.');

        $franchisees = Franchisee::select('id', 'shop_name', 'shop_code')
            ->where('status', 'active')
            ->get();

        return Inertia::render('ShopVisits/Create', [
            'franchisees' => $franchisees,
            'checklistDefaults' => [
                'shop_cleanliness'      => false,
                'product_display'       => false,
                'stock_adequacy'        => false,
                'staff_in_uniform'      => false,
                'pos_system_working'    => false,
                'license_displayed'     => false,
                'fridge_maintained'     => false,
                'feedback_register'     => false,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->franchisee_id) abort(403);

        $validated = $request->validate([
            'franchisee_id'    => 'required|exists:franchisees,id',
            'visit_date'       => 'required|date|before_or_equal:today',
            'notes'            => 'nullable|string',
            'inspection_score' => 'nullable|integer|min:0|max:100',
            'checklist'        => 'nullable|array',
            'status'           => 'required|in:draft,completed',
        ]);

        ShopVisitAudit::create(array_merge($validated, [
            'auditor_id' => $user->id,
        ]));

        return redirect()->route('shop-visits.index')->with('success', 'Shop visit logged successfully.');
    }

    public function show(ShopVisitAudit $shopVisit, Request $request)
    {
        $user = $request->user();

        if ($user->franchisee_id && $shopVisit->franchisee_id !== $user->franchisee_id) {
            abort(403);
        }

        $shopVisit->load(['franchisee', 'auditor:id,name']);

        return Inertia::render('ShopVisits/Show', [
            'audit' => $shopVisit,
        ]);
    }
}
