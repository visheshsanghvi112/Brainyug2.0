<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class MeetingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Meeting::with([
            'creator:id,name',
            'attendees.user:id,name',
        ])
        ->where(function ($q) use ($user) {
            $q->where('created_by', $user->id)
              ->orWhereHas('attendees', fn($q2) => $q2->where('user_id', $user->id));
        })
        ->latest('start_time');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return Inertia::render('Meetings/Index', [
            'meetings' => $query->paginate(20)->withQueryString(),
            'filters'  => $request->only(['status']),
            'canCreate' => $user->isAdmin() || $user->isTerritoryHead(),
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isTerritoryHead()) {
            abort(403, 'Only territory heads and admins can schedule meetings.');
        }

        // Suggest users the organiser manages
        $subordinates = User::where('parent_id', $user->id)
            ->orWhere('id', $user->id)
            ->select('id', 'name', 'email')
            ->get();

        return Inertia::render('Meetings/Create', [
            'subordinates' => $subordinates,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isTerritoryHead()) {
            abort(403);
        }

        $validated = $request->validate([
            'title'            => 'required|string|max:255',
            'description'      => 'nullable|string',
            'start_time'       => 'required|date|after:now',
            'end_time'         => 'required|date|after:start_time',
            'location_or_link' => 'nullable|string|max:500',
            'attendee_ids'     => 'required|array|min:1',
            'attendee_ids.*'   => 'exists:users,id',
        ]);

        DB::transaction(function () use ($validated, $user) {
            $meeting = Meeting::create([
                'title'            => $validated['title'],
                'description'      => $validated['description'],
                'start_time'       => $validated['start_time'],
                'end_time'         => $validated['end_time'],
                'location_or_link' => $validated['location_or_link'],
                'created_by'       => $user->id,
                'status'           => 'scheduled',
            ]);

            foreach ($validated['attendee_ids'] as $attendeeId) {
                MeetingAttendee::create([
                    'meeting_id' => $meeting->id,
                    'user_id'    => $attendeeId,
                    'status'     => 'invited',
                ]);
            }
        });

        return redirect()->route('meetings.index')->with('success', 'Meeting scheduled successfully.');
    }

    public function show(Meeting $meeting, Request $request)
    {
        $user = $request->user();

        // Must be creator or attendee
        $isInvolved = $meeting->created_by === $user->id
            || $meeting->attendees()->where('user_id', $user->id)->exists();

        if (!$isInvolved && !$user->isSuperAdmin()) {
            abort(403);
        }

        $meeting->load([
            'creator:id,name,email',
            'attendees.user:id,name,email',
        ]);

        return Inertia::render('Meetings/Show', [
            'meeting'   => $meeting,
            'isCreator' => $meeting->created_by === $user->id || $user->isSuperAdmin(),
        ]);
    }

    public function updateStatus(Meeting $meeting, Request $request)
    {
        if ($meeting->created_by !== $request->user()->id && !$request->user()->isSuperAdmin()) {
            abort(403);
        }

        $request->validate(['status' => 'required|in:scheduled,in_progress,completed,cancelled']);
        $meeting->update(['status' => $request->status]);

        return back()->with('success', 'Meeting status updated.');
    }

    public function rsvp(Meeting $meeting, Request $request)
    {
        $user = $request->user();
        $request->validate(['status' => 'required|in:attending,declined']);

        $attendee = $meeting->attendees()->where('user_id', $user->id)->first();
        if (!$attendee) abort(403, 'You are not invited to this meeting.');

        $attendee->update(['status' => $request->status]);
        return back()->with('success', 'Response recorded.');
    }
}
