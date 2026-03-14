<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SaltMaster;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SaltMasterController extends Controller
{
    public function index(Request $request)
    {
        $query = SaltMaster::orderBy('name');
        if ($search = $request->input('search')) {
            $query->where('name', 'like', '%' . $search . '%');
        }
        $salts = $query->paginate(15)->withQueryString();
        return Inertia::render('Master/Salt/Index', [
            'salts'   => $salts,
            'filters' => $request->only(['search']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:salt_masters,name|max:255',
            'indication' => 'nullable|string',
            'dosage' => 'nullable|string',
            'side_effects' => 'nullable|string',
            'special_precaution' => 'nullable|string',
            'drug_interaction' => 'nullable|string',
            'is_narcotic' => 'boolean',
            'schedule_h' => 'boolean',
            'schedule_h1' => 'boolean',
            'note' => 'nullable|string',
            'maximum_rate' => 'nullable|string|max:20',
            'continued' => 'nullable|string|max:20',
            'prohibited' => 'nullable|string|max:20',
            'legacy_category_id' => 'nullable|integer',
            'legacy_sub_category_id' => 'nullable|integer',
        ]);

        DB::beginTransaction();
        try {
            SaltMaster::create($validated);
            DB::commit();
            return redirect()->back()->with('success', 'Master record provisioned successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SaltMaster Creation Failed: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Critical Error: Unable to provision master record. Check logs.');
        }
    }

    public function update(Request $request, SaltMaster $saltMaster)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:salt_masters,name,' . $saltMaster->id,
            'indication' => 'nullable|string',
            'dosage' => 'nullable|string',
            'side_effects' => 'nullable|string',
            'special_precaution' => 'nullable|string',
            'drug_interaction' => 'nullable|string',
            'is_narcotic' => 'boolean',
            'schedule_h' => 'boolean',
            'schedule_h1' => 'boolean',
            'note' => 'nullable|string',
            'maximum_rate' => 'nullable|string|max:20',
            'continued' => 'nullable|string|max:20',
            'prohibited' => 'nullable|string|max:20',
            'legacy_category_id' => 'nullable|integer',
            'legacy_sub_category_id' => 'nullable|integer',
        ]);

        DB::beginTransaction();
        try {
            $saltMaster->update($validated);
            DB::commit();
            return redirect()->back()->with('success', 'Master record synchronized successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SaltMaster Update Failed: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Critical Error: Unable to synchronize master record. Check logs.');
        }
    }

    public function destroy(SaltMaster $saltMaster)
    {
        // Enterprise Pre-check: Ensure it's not being actively used.
        if ($saltMaster->products()->exists()) {
            return redirect()->back()->with('error', 'Integrity Constraint Error: Cannot purge a composition that is mapped to active products.');
        }

        DB::beginTransaction();
        try {
            $saltMaster->delete();
            DB::commit();
            return redirect()->back()->with('success', 'Master record archived successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('SaltMaster Deletion Failed: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Critical Error: Unable to archive master record. Check logs.');
        }
    }
}
