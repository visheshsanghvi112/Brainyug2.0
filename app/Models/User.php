<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'password',
        'parent_id',
        'franchisee_id',
        'is_active',
        'google2fa_secret',
        'preferences',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'google2fa_secret',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'preferences' => 'array',
    ];

    protected $appends = [
        'is_2fa_enabled',
    ];

    public function getIs2faEnabledAttribute(): bool
    {
        return !empty($this->google2fa_secret);
    }

    // ══════════════════════════════════════
    //  HIERARCHY RELATIONSHIPS
    // ══════════════════════════════════════

    /** The user who manages/created this user */
    public function parent()
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    /** All direct reports */
    public function children()
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    /** Recursive: all descendants in the hierarchy */
    public function descendants()
    {
        return $this->children()->with('descendants');
    }

    /** Recursive: full parent chain upward */
    public function ancestors()
    {
        return $this->parent()->with('ancestors');
    }

    // ══════════════════════════════════════
    //  TERRITORY RELATIONSHIPS
    // ══════════════════════════════════════

    /** All territory assignments for this user */
    public function territoryAssignments()
    {
        return $this->hasMany(TerritoryAssignment::class);
    }

    /** Get assigned state IDs */
    public function assignedStateIds(): array
    {
        return $this->territoryAssignments()
            ->where('territory_type', 'state')
            ->pluck('territory_id')
            ->toArray();
    }

    /** Get assigned district IDs */
    public function assignedDistrictIds(): array
    {
        return $this->territoryAssignments()
            ->where('territory_type', 'district')
            ->pluck('territory_id')
            ->toArray();
    }

    // ══════════════════════════════════════
    //  FRANCHISEE RELATIONSHIPS
    // ══════════════════════════════════════

    /** The franchisee this user belongs to (if they are a franchise owner/staff) */
    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }

    /** Staff positions at franchisees */
    public function staffPositions()
    {
        return $this->hasMany(FranchiseeStaff::class);
    }

    /** Franchisees managed by this user as District Head */
    public function managedFranchiseesDH()
    {
        return $this->hasMany(Franchisee::class, 'district_head_id');
    }

    /** Franchisees managed by this user as Zone Head */
    public function managedFranchiseesZH()
    {
        return $this->hasMany(Franchisee::class, 'zone_head_id');
    }

    /** Franchisees managed by this user as State Head */
    public function managedFranchiseesSH()
    {
        return $this->hasMany(Franchisee::class, 'state_head_id');
    }

    // ══════════════════════════════════════
    //  ROLE HELPERS
    // ══════════════════════════════════════

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('Super Admin');
    }

    public function isStateHead(): bool
    {
        return $this->hasRole('State Head');
    }

    public function isZoneHead(): bool
    {
        return $this->hasRole('Zone Head');
    }

    public function isDistrictHead(): bool
    {
        return $this->hasRole('District Head');
    }

    public function isFranchisee(): bool
    {
        return $this->hasRole('Franchisee');
    }

    public function isDistributor(): bool
    {
        return $this->hasRole('Distributor');
    }

    // ══════════════════════════════════════
    //  SCOPES
    // ══════════════════════════════════════

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeChildrenOf($query, $parentId)
    {
        return $query->where('parent_id', $parentId);
    }

    /**
     * Scope to users within a territory (state or district).
     * Uses territory_assignments for heads, franchisee location for franchise users.
     */
    public function scopeInTerritory($query, string $type, int $territoryId)
    {
        return $query->where(function ($q) use ($type, $territoryId) {
            // Users directly assigned to this territory
            $q->whereHas('territoryAssignments', function ($ta) use ($type, $territoryId) {
                $ta->where('territory_type', $type)
                   ->where('territory_id', $territoryId);
            })
            // OR users whose franchisee is in this territory
            ->orWhereHas('franchisee', function ($f) use ($type, $territoryId) {
                if ($type === 'state') {
                    $f->where('state_id', $territoryId);
                } elseif ($type === 'district') {
                    $f->where('district_id', $territoryId);
                }
            });
        });
    }

    /**
     * Get the effective franchisee_id for this user.
     * If user is a direct franchisee owner → their own franchisee_id.
     * If user is staff → their staff franchise.
     * Legacy: getFranchId()
     */
    public function getEffectiveFranchiseeId(): ?int
    {
        if ($this->franchisee_id) {
            return $this->franchisee_id;
        }

        // Check if staff at a franchisee
        $staffPos = $this->staffPositions()->where('is_active', true)->first();
        return $staffPos?->franchisee_id;
    }
}
