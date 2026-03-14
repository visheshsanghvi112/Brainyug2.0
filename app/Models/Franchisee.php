<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Franchisee extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        // Shop Identity
        'shop_code', 'shop_name', 'shop_type',
        // Owner
        'owner_name', 'owner_title', 'partner_name', 'partner_title',
        'owner_dob', 'owner_age', 'education', 'occupation',
        // Contact
        'email', 'mobile', 'whatsapp', 'alternate_phone',
        // Address
        'address', 'state_id', 'district_id', 'city_id', 'other_city',
        'pincode', 'latitude', 'longitude',
        // Residence
        'residence_address', 'residence_from', 'distance_from_shop',
        // Legal
        'gst_number', 'pan_number', 'dl_number_20b', 'dl_number_21b',
        'dl_number_third', 'fssai_number',
        // Financial
        'bank_name', 'bank_account_holder', 'bank_account_number',
        'bank_ifsc', 'bank_branch', 'utr_number', 'transaction_date',
        'investment_amount', 'ready_to_invest',
        // Documents
        'documents',
        // Approval
        'status', 'approved_by', 'approved_at', 'activated_at',
        'deactivated_at', 'rejection_reason',
        // Hierarchy
        'district_head_id', 'zone_head_id', 'state_head_id',
        // Migration traceability
        'legacy_source', 'legacy_franchise_id',
    ];

    protected $casts = [
        'documents' => 'json',
        'ready_to_invest' => 'boolean',
        'owner_dob' => 'date',
        'transaction_date' => 'date',
        'approved_at' => 'datetime',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'investment_amount' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    // ─── Status Checks ───

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['registered', 'enquiry']);
    }

    // ─── Relationships ───

    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function districtHead()
    {
        return $this->belongsTo(User::class, 'district_head_id');
    }

    public function zoneHead()
    {
        return $this->belongsTo(User::class, 'zone_head_id');
    }

    public function stateHead()
    {
        return $this->belongsTo(User::class, 'state_head_id');
    }

    /** All users linked to this franchisee (owner + staff) */
    public function users()
    {
        return $this->hasMany(User::class, 'franchisee_id');
    }

    /** Staff members (excluding the owner) */
    public function staff()
    {
        return $this->hasMany(FranchiseeStaff::class);
    }

    // ─── Scopes ───

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['registered', 'enquiry']);
    }

    public function scopeInState($query, $stateId)
    {
        return $query->where('state_id', $stateId);
    }

    public function scopeInDistrict($query, $districtId)
    {
        return $query->where('district_id', $districtId);
    }

    public function scopeInDistricts($query, array $districtIds)
    {
        return $query->whereIn('district_id', $districtIds);
    }
}
