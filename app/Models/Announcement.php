<?php

namespace App\Models;

use App\Support\ErpRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Announcement extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'target_roles' => 'array',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
        'is_pinned' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActiveWindow(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function ($inner) {
                $inner->whereNull('valid_from')->orWhere('valid_from', '<=', now());
            })
            ->where(function ($inner) {
                $inner->whereNull('valid_to')->orWhere('valid_to', '>=', now());
            });
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $canonicalRoles = collect($user->getRoleNames()->all())
            ->map(fn ($role) => ErpRole::canonicalize($role))
            ->unique()
            ->values()
            ->all();

        return $query->activeWindow()->where(function ($inner) use ($canonicalRoles) {
            $inner->whereNull('target_roles')
                ->orWhereRaw('JSON_LENGTH(target_roles) = 0');

            foreach ($canonicalRoles as $role) {
                $inner->orWhereJsonContains('target_roles', $role);
            }
        });
    }
}