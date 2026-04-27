<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseReturn extends Model
{
    use HasFactory, SoftDeletes;

    protected $appends = ['workflow_status', 'is_reversed'];

    protected $fillable = [
        'return_number', 'supplier_id', 'purchase_invoice_id',
        'return_date', 'financial_year',
        'subtotal', 'sgst_amount', 'cgst_amount', 'igst_amount', 'total_amount',
        'status', 'reason', 'created_by', 'approved_by',
        'reversed_by', 'reversed_at', 'reversal_reason',
    ];

    protected $casts = [
        'return_date' => 'date',
        'total_amount' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function purchaseInvoice() { return $this->belongsTo(PurchaseInvoice::class); }
    public function sourceInvoices()
    {
        return $this->belongsToMany(PurchaseInvoice::class, 'purchase_return_source_invoices')
            ->withTimestamps();
    }
    public function items() { return $this->hasMany(PurchaseReturnItem::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by'); }
    public function reversedBy() { return $this->belongsTo(User::class, 'reversed_by'); }

    public function scopeApprovedActive($query)
    {
        return $query->where('status', 'approved')->whereNull('reversed_at');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function isApprovedActive(): bool
    {
        return $this->status === 'approved' && !$this->isReversed();
    }

    public function canReverse(): bool
    {
        return $this->isApprovedActive();
    }

    public function getWorkflowStatusAttribute(): string
    {
        $status = (string) ($this->attributes['status'] ?? $this->getRawOriginal('status') ?? 'draft');

        return $this->isReversed() ? 'reversed' : $status;
    }

    public function getIsReversedAttribute(): bool
    {
        return $this->isReversed();
    }
}
