<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'contact_person', 'phone', 'email',
        'address', 'state_id', 'district_id', 'pincode',
        'gst_number', 'pan_number', 'dl_number',
        'bank_name', 'bank_account_number', 'bank_ifsc',
        'credit_limit', 'credit_days', 'supplier_type', 'notes', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credit_days' => 'integer',
        'credit_limit' => 'decimal:2',
    ];

    public function state() { return $this->belongsTo(State::class); }
    public function district() { return $this->belongsTo(District::class); }
    public function purchaseInvoices() { return $this->hasMany(PurchaseInvoice::class); }
    public function purchaseReturns() { return $this->hasMany(PurchaseReturn::class); }
    public function financialLedgers() { return $this->morphMany(FinancialLedger::class, 'ledgerable'); }

    public function scopeActive($q) { return $q->where('is_active', true); }
}
