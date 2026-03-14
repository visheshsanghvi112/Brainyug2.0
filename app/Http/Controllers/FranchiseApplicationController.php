<?php

namespace App\Http\Controllers;

use App\Models\District;
use App\Models\Franchisee;
use App\Models\State;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FranchiseApplicationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Franchise/Apply', [
            'states' => State::query()->orderBy('name')->get(['id', 'name']),
            'districts' => District::query()->orderBy('name')->get(['id', 'name', 'state_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shop_name' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'mobile' => [
                'required',
                'string',
                'max:15',
                'regex:/^([0-9\s\-\+\(\)]*)$/',
                Rule::unique('franchisees', 'mobile')->whereNull('deleted_at'),
            ],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('franchisees', 'email')->whereNull('deleted_at')],
            'state_id' => ['required', 'exists:states,id'],
            'district_id' => ['required', 'exists:districts,id'],
            'address' => ['nullable', 'string'],
            'whatsapp' => ['nullable', 'string', 'max:15', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
            'gst_number' => ['nullable', 'string', 'max:20'],
            'investment_amount' => ['nullable', 'numeric', 'min:0'],
            'ready_to_invest' => ['boolean'],
        ]);

        $validated['shop_name'] = trim($validated['shop_name']);
        $validated['owner_name'] = trim($validated['owner_name']);
        $validated['email'] = isset($validated['email']) ? Str::lower(trim($validated['email'])) : null;
        $validated['gst_number'] = isset($validated['gst_number']) ? Str::upper(trim($validated['gst_number'])) : null;
        $validated['status'] = 'enquiry';

        Franchisee::create($validated);

        return redirect()->route('franchise.apply')->with('success', 'Application submitted. The registration will move through review, approval, and activation before any ERP login is provisioned.');
    }
}