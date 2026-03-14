<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class UserAccessAuditController extends Controller
{
    public function index(Request $request): Response
    {
        if (!Schema::hasTable('user_access_change_audits')) {
            $audits = new LengthAwarePaginator(
                items: [],
                total: 0,
                perPage: 30,
                currentPage: max(1, (int) $request->input('page', 1)),
                options: [
                    'path' => $request->url(),
                    'query' => $request->query(),
                ]
            );

            return Inertia::render('Admin/UserAccess/AuditIndex', [
                'audits' => $audits,
                'filters' => $request->only(['search', 'event_type', 'actor_id', 'target_id', 'from', 'to']),
                'eventOptions' => ['created', 'updated', 'deleted'],
                'notice' => 'Access audit table is missing in this environment. Run migrations to enable user access history.',
            ]);
        }

        $query = DB::table('user_access_change_audits as audit')
            ->leftJoin('users as actor_user', 'actor_user.id', '=', 'audit.actor_user_id')
            ->leftJoin('users as target_user', 'target_user.id', '=', 'audit.target_user_id')
            ->select([
                'audit.id',
                'audit.actor_user_id',
                'audit.target_user_id',
                'audit.event_type',
                'audit.summary',
                'audit.before_state',
                'audit.after_state',
                'audit.meta',
                'audit.ip_address',
                'audit.user_agent',
                'audit.created_at',
                'actor_user.name as actor_user_name',
                'actor_user.email as actor_user_email',
                'target_user.name as target_user_name',
                'target_user.email as target_user_email',
            ])
            ->orderByDesc('audit.id');

        $query->when($request->filled('event_type'), function (Builder $builder) use ($request) {
            $builder->where('audit.event_type', (string) $request->input('event_type'));
        });

        $query->when($request->filled('actor_id'), function (Builder $builder) use ($request) {
            $builder->where('audit.actor_user_id', (int) $request->input('actor_id'));
        });

        $query->when($request->filled('target_id'), function (Builder $builder) use ($request) {
            $builder->where('audit.target_user_id', (int) $request->input('target_id'));
        });

        $query->when($request->filled('search'), function (Builder $builder) use ($request) {
            $search = trim((string) $request->input('search'));
            $builder->where(function (Builder $nested) use ($search) {
                $nested->where('actor_user.name', 'like', "%{$search}%")
                    ->orWhere('actor_user.email', 'like', "%{$search}%")
                    ->orWhere('target_user.name', 'like', "%{$search}%")
                    ->orWhere('target_user.email', 'like', "%{$search}%")
                    ->orWhere('audit.summary', 'like', "%{$search}%")
                    ->orWhere('audit.ip_address', 'like', "%{$search}%");
            });
        });

        $query->when($request->filled('from'), function (Builder $builder) use ($request) {
            $builder->whereDate('audit.created_at', '>=', (string) $request->input('from'));
        });

        $query->when($request->filled('to'), function (Builder $builder) use ($request) {
            $builder->whereDate('audit.created_at', '<=', (string) $request->input('to'));
        });

        $audits = $query->paginate(30)->withQueryString();

        return Inertia::render('Admin/UserAccess/AuditIndex', [
            'audits' => $audits,
            'filters' => $request->only(['search', 'event_type', 'actor_id', 'target_id', 'from', 'to']),
            'eventOptions' => ['created', 'updated', 'deleted'],
            'notice' => null,
        ]);
    }
}
