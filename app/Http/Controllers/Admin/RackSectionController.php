<?php

namespace App\Http\Controllers\Admin;

use App\Models\RackSection;
use App\Models\RackArea;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RackSectionController extends Controller
{
    /**
     * Display all rack sections with their areas.
     */
    public function index()
    {
        $sections = RackSection::with('areas')
            ->orderBy('name')
            ->get();

        return inertia('Master/Racks/Index', [
            'sections' => $sections,
        ]);
    }

    /**
     * Store a new rack section (AJAX).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:rack_sections,name',
            'warehouse_location' => 'nullable|string|max:200',
        ]);

        $section = RackSection::create($validated);

        return response()->json([
            'success' => true,
            'section' => $section,
            'message' => 'Rack section created successfully.',
        ], 201);
    }

    /**
     * Store a new rack area under a section (AJAX).
     */
    public function storeArea(Request $request)
    {
        $validated = $request->validate([
            'rack_section_id' => 'required|exists:rack_sections,id',
            'name' => 'required|string|max:100',
            'capacity' => 'nullable|integer|min:1',
            'description' => 'nullable|string|max:500',
        ]);

        // Check for duplicate area name within the same section
        $exists = RackArea::where('rack_section_id', $validated['rack_section_id'])
            ->where('name', $validated['name'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Area name already exists in this section.',
            ], 422);
        }

        $area = RackArea::create($validated);

        return response()->json([
            'success' => true,
            'area' => $area,
            'message' => 'Rack area created successfully.',
        ], 201);
    }

    /**
     * Update a rack section (AJAX).
     */
    public function update(Request $request, RackSection $section)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:rack_sections,name,' . $section->id,
            'warehouse_location' => 'nullable|string|max:200',
        ]);

        $section->update($validated);

        return response()->json([
            'success' => true,
            'section' => $section,
            'message' => 'Rack section updated successfully.',
        ]);
    }

    /**
     * Delete a rack section (AJAX).
     */
    public function destroy(Request $request, RackSection $section)
    {
        // Check if section has areas
        if ($section->areas()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete section with existing areas. Delete areas first.',
            ], 422);
        }

        // Check if section is used in any products
        if ($section->rackAreas()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Rack section is in use. Cannot delete.',
            ], 422);
        }

        $section->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rack section deleted successfully.',
        ]);
    }

    /**
     * Delete a rack area (AJAX).
     */
    public function destroyArea(Request $request, RackArea $area)
    {
        // Check if area is used in any products
        if ($area->products()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Rack area is in use by products. Cannot delete.',
            ], 422);
        }

        $sectionId = $area->rack_section_id;
        $area->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rack area deleted successfully.',
        ]);
    }
}
