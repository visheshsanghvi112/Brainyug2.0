<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\ShopVisitAudit;
use App\Models\FranchiseeFeedback;
use Illuminate\Support\Facades\DB;

class OperationalController extends Controller
{
    // ══════════════════════════════════════
    //  MEETING MANAGEMENT (State/District Heads & Franchisees)
    // ══════════════════════════════════════

    public function meetings(Request $request)
    {
        $user = $request->user();

        // If Super Admin or Territory Head, fetch meetings they created, or are attending.
        // For simplicity, fetch meetings they are attending OR created.
        $meetings = Meeting::with('attendees.user:id,name,email')
            ->where(function($query) use ($user) {
                $query->where('created_by', $user->id)
                      ->orWhereHas('attendees', function($q) use ($user) {
                          $q->where('user_id', $user->id);
                      });
            })
            ->latest('start_time')
            ->paginate(15);

        return response()->json($meetings);
    }

    public function createMeeting(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'location_or_link' => 'nullable|string',
            'attendee_ids' => 'required|array',
            'attendee_ids.*' => 'exists:users,id'
        ]);

        try {
            DB::beginTransaction();
            $meeting = Meeting::create([
                'title' => $request->title,
                'description' => $request->description,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
                'location_or_link' => $request->location_or_link,
                'created_by' => $request->user()->id,
                'status' => 'scheduled'
            ]);

            foreach ($request->attendee_ids as $id) {
                MeetingAttendee::create([
                    'meeting_id' => $meeting->id,
                    'user_id' => $id,
                    'status' => 'invited'
                ]);
            }
            DB::commit();

            return response()->json(['message' => 'Meeting scheduled', 'meeting' => $meeting], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create meeting'], 500);
        }
    }

    // ══════════════════════════════════════
    //  SUPPORT TICKETS (Franchisee -> HO)
    // ══════════════════════════════════════

    public function tickets(Request $request)
    {
        $user = $request->user();
        
        $query = SupportTicket::with(['replies', 'creator:id,name']);

        if ($user->isFranchisee()) {
            $query->where('franchisee_id', $user->getEffectiveFranchiseeId());
        }

        return response()->json($query->latest()->paginate(15));
    }

    public function createTicket(Request $request)
    {
        $request->validate([
            'subject' => 'required|string',
            'description' => 'required|string',
            'priority' => 'in:low,normal,high,urgent'
        ]);

        $user = $request->user();
        $franchiseId = $user->getEffectiveFranchiseeId();
        
        if (!$franchiseId) {
            return response()->json(['error' => 'No active franchise.'], 403);
        }

        $ticket = SupportTicket::create([
            'franchisee_id' => $franchiseId,
            'user_id' => $user->id,
            'subject' => $request->subject,
            'description' => $request->description,
            'priority' => $request->priority ?? 'normal',
            'status' => 'open'
        ]);

        return response()->json(['message' => 'Ticket created', 'ticket' => $ticket], 201);
    }

    public function replyTicket(Request $request, $id)
    {
        $request->validate(['reply_text' => 'required|string']);

        $ticket = SupportTicket::findOrFail($id);

        $reply = SupportTicketReply::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'reply_text' => $request->reply_text,
        ]);

        // Auto update ticket status if closed/resolved not set
        if ($ticket->status === 'open') {
             $ticket->update(['status' => 'in_progress']);
        }

        return response()->json(['message' => 'Reply added', 'reply' => $reply]);
    }

    // ══════════════════════════════════════
    //  SHOP VISIT AUDITS (Field Staff -> Franchisees)
    // ══════════════════════════════════════

    public function submitAudit(Request $request)
    {
        $request->validate([
            'franchisee_id' => 'required|exists:franchisees,id',
            'visit_date' => 'required|date',
            'inspection_score' => 'required|integer|min:0|max:100',
            'checklist' => 'required|array', // e.g. ["cleanliness" => true, "stock_arranged" => false]
            'notes' => 'required|string'
        ]);

        $audit = ShopVisitAudit::create([
            'franchisee_id' => $request->franchisee_id,
            'auditor_id' => $request->user()->id,
            'visit_date' => $request->visit_date,
            'inspection_score' => $request->inspection_score,
            'checklist' => $request->checklist,
            'notes' => $request->notes,
            'status' => 'completed'
        ]);

        return response()->json(['message' => 'Audit submitted', 'audit' => $audit], 201);
    }
}
