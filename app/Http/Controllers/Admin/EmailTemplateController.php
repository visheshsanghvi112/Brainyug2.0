<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmailTemplateController extends Controller
{
    public function index(Request $request)
    {
        $query = EmailTemplate::query()
            ->with(['creator:id,name', 'updater:id,name'])
            ->orderByDesc('is_active')
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $query->where(function ($inner) use ($search) {
                $inner->where('name', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('module', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        if ($request->filled('module')) {
            $query->where('module', $request->input('module'));
        }

        if ($request->filled('status')) {
            if ($request->input('status') === 'active') {
                $query->where('is_active', true);
            }

            if ($request->input('status') === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $moduleOptions = EmailTemplate::query()
            ->whereNotNull('module')
            ->where('module', '!=', '')
            ->distinct()
            ->orderBy('module')
            ->pluck('module')
            ->values();

        return Inertia::render('Communication/EmailTemplates/Index', [
            'emailTemplates' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only(['search', 'module', 'status']),
            'moduleOptions' => $moduleOptions,
        ]);
    }

    public function create()
    {
        return Inertia::render('Communication/EmailTemplates/CreateEdit', [
            'moduleSuggestions' => $this->moduleSuggestions(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatedPayload($request);

        EmailTemplate::create($validated + [
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.email-templates.index')->with('success', 'Email template created.');
    }

    public function edit(EmailTemplate $emailTemplate)
    {
        return Inertia::render('Communication/EmailTemplates/CreateEdit', [
            'emailTemplate' => $emailTemplate,
            'moduleSuggestions' => $this->moduleSuggestions(),
        ]);
    }

    public function update(Request $request, EmailTemplate $emailTemplate)
    {
        $validated = $this->validatedPayload($request, $emailTemplate->id);

        $emailTemplate->update($validated + [
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('admin.email-templates.index')->with('success', 'Email template updated.');
    }

    public function destroy(EmailTemplate $emailTemplate)
    {
        $emailTemplate->delete();

        return redirect()->route('admin.email-templates.index')->with('success', 'Email template archived.');
    }

    private function validatedPayload(Request $request, ?int $ignoreId = null): array
    {
        $uniqueNameRule = 'unique:email_templates,name';
        if ($ignoreId) {
            $uniqueNameRule .= ',' . $ignoreId;
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:120', $uniqueNameRule],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:20000'],
            'module' => ['nullable', 'string', 'max:100'],
            'variables' => ['nullable', 'array'],
            'variables.*' => ['nullable', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    private function moduleSuggestions(): array
    {
        return [
            'Franchise Onboarding',
            'Procurement',
            'Distribution',
            'Billing',
            'Support',
            'Finance',
            'Compliance',
        ];
    }
}
