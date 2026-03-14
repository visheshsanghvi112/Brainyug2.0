<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\Franchisee;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupportTicketController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = SupportTicket::with(['franchisee:id,shop_name,shop_code', 'user:id,name', 'assignedTo:id,name'])
            ->withCount('replies')
            ->latest();

        if ($user->franchisee_id) {
            // Franchisee only sees their own tickets
            $query->where('franchisee_id', $user->franchisee_id);
        } elseif (!$user->isAdmin() && !$user->isTerritoryHead()) {
            abort(403);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('search')) {
            $query->where('subject', 'like', "%{$request->search}%");
        }

        // HO staff can see tickets assigned to them
        if ($request->boolean('assigned_to_me')) {
            $query->where('assigned_to', $user->id);
        }

        return Inertia::render('Tickets/Index', [
            'tickets' => $query->paginate(20)->withQueryString(),
            'filters' => $request->only(['status', 'priority', 'search', 'assigned_to_me']),
            'isAdmin' => $user->isAdmin() || $user->isTerritoryHead(),
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        if (!$user->franchisee_id) abort(403, 'Only franchisees can raise tickets.');

        return Inertia::render('Tickets/Create');
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->franchisee_id) abort(403);

        $validated = $request->validate([
            'subject'     => 'required|string|max:255',
            'description' => 'required|string',
            'priority'    => 'required|in:low,normal,high,urgent',
        ]);

        SupportTicket::create([
            'franchisee_id' => $user->franchisee_id,
            'user_id'       => $user->id,
            'subject'       => $validated['subject'],
            'description'   => $validated['description'],
            'priority'      => $validated['priority'],
            'status'        => 'open',
        ]);

        return redirect()->route('tickets.index')->with('success', 'Ticket submitted. We will get back to you shortly.');
    }

    public function show(SupportTicket $ticket, Request $request)
    {
        $user = $request->user();

        // Franchisee can only view their own
        if ($user->franchisee_id && $ticket->franchisee_id !== $user->franchisee_id) {
            abort(403);
        }

        $ticket->load([
            'franchisee:id,shop_name,shop_code,mobile',
            'user:id,name',
            'assignedTo:id,name',
            'replies.user:id,name',
        ]);

        $adminUsers = $user->hasErpRole('Super Admin')
            ? User::role(['Super Admin', 'State Head'])->select('id', 'name')->get()
            : collect();

        return Inertia::render('Tickets/Show', [
            'ticket'     => $ticket,
            'adminUsers' => $adminUsers,
            'isAdmin'    => $user->isAdmin() || $user->isTerritoryHead(),
        ]);
    }

    public function reply(SupportTicket $ticket, Request $request)
    {
        $user = $request->user();
        if ($user->franchisee_id && $ticket->franchisee_id !== $user->franchisee_id) {
            abort(403);
        }

        $validated = $request->validate(['reply_text' => 'required|string']);

        SupportTicketReply::create([
            'support_ticket_id' => $ticket->id,
            'user_id'           => $user->id,
            'reply_text'        => $validated['reply_text'],
        ]);

        // Auto-transition status
        if ($user->isAdmin() || $user->isTerritoryHead()) {
            if ($ticket->status === 'open') {
                $ticket->update(['status' => 'in_progress']);
            }
        }

        return back()->with('success', 'Reply sent.');
    }

    public function updateStatus(SupportTicket $ticket, Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isTerritoryHead()) {
            abort(403);
        }

        $validated = $request->validate([
            'status'      => 'required|in:open,in_progress,resolved,closed',
            'assigned_to' => 'nullable|exists:users,id',
        ]);

        $ticket->update($validated);
        return back()->with('success', 'Ticket updated.');
    }
}
