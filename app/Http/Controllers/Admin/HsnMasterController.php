<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HsnMaster;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HsnMasterController extends Controller
{
    public function index()
    {
        $hsnCodes = HsnMaster::orderBy('hsn_code')->paginate(15);
        return Inertia::render('Master/Hsn/Index', ['hsnCodes' => $hsnCodes]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'hsn_code' => 'required|string|unique:hsn_masters,hsn_code|max:20',
            'hsn_name' => 'nullable|string|max:100',
            'unit' => 'nullable|string|max:30',
            'cgst_percent' => 'required|numeric|min:0|max:100',
            'sgst_percent' => 'required|numeric|min:0|max:100',
            'igst_percent' => 'required|numeric|min:0|max:100',
        ]);

        // Standardize HSN Code Format (Enterprise Grade cleanliness)
        $validated['hsn_code'] = Str::upper(trim($validated['hsn_code']));

        DB::beginTransaction();
        try {
            HsnMaster::create($validated);
            DB::commit();
            return redirect()->back()->with('success', 'Tax compliance master mapped successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('HSN Master Provisioning Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Critical Data Issue: Check transaction logs.');
        }
    }

    public function update(Request $request, HsnMaster $hsnMaster)
    {
        $validated = $request->validate([
            'hsn_code' => 'required|string|max:20|unique:hsn_masters,hsn_code,' . $hsnMaster->id,
            'hsn_name' => 'nullable|string|max:100',
            'unit' => 'nullable|string|max:30',
            'cgst_percent' => 'required|numeric|min:0|max:100',
            'sgst_percent' => 'required|numeric|min:0|max:100',
            'igst_percent' => 'required|numeric|min:0|max:100',
        ]);

        $validated['hsn_code'] = Str::upper(trim($validated['hsn_code']));

        DB::beginTransaction();
        try {
            $hsnMaster->update($validated);
            DB::commit();
            return redirect()->back()->with('success', 'Tax structure recalibrated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('HSN Master Synchronizing Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Tax Sync Failure: Check transaction logs.');
        }
    }

    public function destroy(HsnMaster $hsnMaster)
    {
        // Enterprise Pre-check: Ensure it's not being actively mapped by inventory.
        if ($hsnMaster->products()->exists()) {
            return redirect()->back()->with('error', 'Referential Integrity Block: This HSN node is actively utilized by the product catalog.');
        }

        DB::beginTransaction();
        try {
            $hsnMaster->delete();
            DB::commit();
            return redirect()->back()->with('success', 'Tax node depreciated effectively.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('HSN Master Deletion Error: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Depreciation Sequence Failed: See event logs.');
        }
    }
}
