<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class SupportAccessAuditController extends Controller
{
    public function index(Request $request): Response
    {
        if (!Schema::hasTable('impersonation_audits')) {
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

            return Inertia::render('Admin/SupportAccess/AuditIndex', [
                'audits' => $audits,
                'filters' => $request->only(['search', 'action', 'admin_id', 'accessed_id', 'from', 'to']),
                'actionOptions' => ['start', 'request', 'stop'],
                'notice' => 'Audit table is missing in this environment. Run migrations to enable support access history.',
            ]);
        }

        $query = DB::table('impersonation_audits as audit')
            ->leftJoin('users as admin_user', 'admin_user.id', '=', 'audit.admin_user_id')
            ->leftJoin('users as accessed_user', 'accessed_user.id', '=', 'audit.impersonated_user_id')
            ->select([
                'audit.id',
                'audit.admin_user_id',
                'audit.impersonated_user_id',
                'audit.action',
                'audit.reason',
                'audit.method',
                'audit.path',
                'audit.response_status',
                'audit.ip_address',
                'audit.user_agent',
                'audit.created_at',
                'admin_user.name as admin_user_name',
                'admin_user.email as admin_user_email',
                'accessed_user.name as accessed_user_name',
                'accessed_user.email as accessed_user_email',
            ])
            ->orderByDesc('audit.id');

        $query->when($request->filled('action'), function (Builder $builder) use ($request) {
            $builder->where('audit.action', (string) $request->input('action'));
        });

        $query->when($request->filled('admin_id'), function (Builder $builder) use ($request) {
            $builder->where('audit.admin_user_id', (int) $request->input('admin_id'));
        });

        $query->when($request->filled('accessed_id'), function (Builder $builder) use ($request) {
            $builder->where('audit.impersonated_user_id', (int) $request->input('accessed_id'));
        });

        $query->when($request->filled('search'), function (Builder $builder) use ($request) {
            $search = trim((string) $request->input('search'));
            $builder->where(function (Builder $nested) use ($search) {
                $nested->where('admin_user.name', 'like', "%{$search}%")
                    ->orWhere('admin_user.email', 'like', "%{$search}%")
                    ->orWhere('accessed_user.name', 'like', "%{$search}%")
                    ->orWhere('accessed_user.email', 'like', "%{$search}%")
                    ->orWhere('audit.ip_address', 'like', "%{$search}%")
                    ->orWhere('audit.path', 'like', "%{$search}%");
            });
        });

        $query->when($request->filled('from'), function (Builder $builder) use ($request) {
            $builder->whereDate('audit.created_at', '>=', (string) $request->input('from'));
        });

        $query->when($request->filled('to'), function (Builder $builder) use ($request) {
            $builder->whereDate('audit.created_at', '<=', (string) $request->input('to'));
        });

        $audits = $query->paginate(30)->withQueryString();

        return Inertia::render('Admin/SupportAccess/AuditIndex', [
            'audits' => $audits,
            'filters' => $request->only(['search', 'action', 'admin_id', 'accessed_id', 'from', 'to']),
            'actionOptions' => ['start', 'request', 'stop'],
            'notice' => null,
        ]);
    }
}
