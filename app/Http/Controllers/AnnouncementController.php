<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Support\ErpRole;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AnnouncementController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $canManage = $user->isAdmin();

        $query = Announcement::query()
            ->with('creator:id,name')
            ->orderByDesc('is_pinned')
            ->orderByDesc('valid_from')
            ->orderByDesc('id');

        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $query->where(function ($inner) use ($term) {
                $inner->where('title', 'like', "%{$term}%")
                    ->orWhere('body', 'like', "%{$term}%");
            });
        }

        if ($canManage) {
            if ($request->filled('status')) {
                if ($request->input('status') === 'active') {
                    $query->activeWindow();
                }

                if ($request->input('status') === 'inactive') {
                    $query->where(function ($inner) {
                        $inner->where('is_active', false)
                            ->orWhere('valid_to', '<', now())
                            ->orWhere('valid_from', '>', now());
                    });
                }
            }
        } else {
            $query->visibleTo($user);
        }

        return Inertia::render('Announcements/Index', [
            'announcements' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only(['search', 'status']),
            'canManage' => $canManage,
        ]);
    }

    public function create(Request $request)
    {
        $this->ensureAdmin($request);

        return Inertia::render('Announcements/CreateEdit', [
            'roleOptions' => ErpRole::canonicalRoles(),
        ]);
    }

    public function store(Request $request)
    {
        $this->ensureAdmin($request);

        Announcement::create($this->validatedPayload($request) + [
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('announcements.index')->with('success', 'Announcement published.');
    }

    public function edit(Announcement $announcement, Request $request)
    {
        $this->ensureAdmin($request);

        return Inertia::render('Announcements/CreateEdit', [
            'announcement' => $announcement,
            'roleOptions' => ErpRole::canonicalRoles(),
        ]);
    }

    public function update(Announcement $announcement, Request $request)
    {
        $this->ensureAdmin($request);

        $announcement->update($this->validatedPayload($request));

        return redirect()->route('announcements.index')->with('success', 'Announcement updated.');
    }

    public function destroy(Announcement $announcement, Request $request)
    {
        $this->ensureAdmin($request);

        $announcement->delete();

        return redirect()->route('announcements.index')->with('success', 'Announcement archived.');
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403);
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string|max:5000',
            'target_roles' => 'nullable|array',
            'target_roles.*' => 'string|in:' . implode(',', ErpRole::canonicalRoles()),
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'is_pinned' => 'required|boolean',
            'is_active' => 'required|boolean',
        ]);
    }
}