<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_number',
        'supplier_id',
        'order_date',
        'required_date',
        'expected_delivery_date',
        'financial_year',
        'subtotal',
        'discount_amount',
        'sgst_amount',
        'cgst_amount',
        'igst_amount',
        'round_off',
        'total_amount',
        'tax_type',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'sent_at',
        'received_at',
        'transporter',
        'lr_number',
        'transport_cost',
        'purchase_invoice_id',
        'notes',
        'quote_reference',
    ];

    protected $casts = [
        'order_date' => 'date',
        'required_date' => 'date',
        'expected_delivery_date' => 'date',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'round_off' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'transport_cost' => 'decimal:2',
        'quote_reference' => 'json',
    ];

    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', '!=', 'cancelled')->where('status', '!=', 'draft');
    }

    public function scopeOverdue($query)
    {
        return $query->where('expected_delivery_date', '<', now()->toDateString())
            ->whereNotIn('status', ['cancelled', 'invoiced']);
    }

    // Relationships
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    // Methods
    public static function generateNextOrderNumber($fy): string
    {
        $prefix = 'PO-' . $fy;
        $lastPo = self::where('order_number', 'like', $prefix . '-%')
            ->latest('id')
            ->first();

        $nextSequence = $lastPo 
            ? (int)substr($lastPo->order_number, strlen($prefix) + 1) + 1 
            : 1;

        return $prefix . '-' . str_pad($nextSequence, 6, '0', STR_PAD_LEFT);
    }

    public function canApprove(): bool
    {
        return $this->status === 'draft' && !$this->deleted_at;
    }

    public function canSend(): bool
    {
        return $this->status === 'approved' && !$this->deleted_at;
    }

    public function canReceive(): bool
    {
        return in_array($this->status, ['sent', 'approved']) && !$this->deleted_at;
    }

    public function canCancel(): bool
    {
        return in_array($this->status, ['draft', 'approved']) && !$this->deleted_at;
    }

    public function calculateTotals(): void
    {
        $this->subtotal = $this->items()->sum('line_total');
        $this->sgst_amount = $this->tax_type === 'intra_state' 
            ? $this->items()->sum('gst_amount') / 2 
            : 0;
        $this->cgst_amount = $this->tax_type === 'intra_state' 
            ? $this->items()->sum('gst_amount') / 2 
            : 0;
        $this->igst_amount = $this->tax_type === 'inter_state' 
            ? $this->items()->sum('gst_amount') 
            : 0;

        $this->total_amount = $this->subtotal 
            + $this->sgst_amount 
            + $this->cgst_amount 
            + $this->igst_amount 
            - $this->discount_amount 
            + $this->round_off 
            + ($this->transport_cost ?? 0);
    }

    public function getStatusBadgeColorAttribute(): string
    {
        return match($this->status) {
            'draft' => 'gray',
            'approved' => 'blue',
            'sent' => 'indigo',
            'received' => 'yellow',
            'invoiced' => 'green',
            'cancelled' => 'red',
            default => 'gray',
        };
    }
}
