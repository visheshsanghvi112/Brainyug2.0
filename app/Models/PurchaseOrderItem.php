<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'qty_ordered',
        'qty_received',
        'qty_rejected',
        'qty_free',
        'mrp',
        'rate',
        'line_amount',
        'unit',
        'gst_percent',
        'gst_amount',
        'line_total',
        'mfg_date',
        'expiry_date',
        'batch_no',
        'discount_percent',
        'discount_amount',
        'purchase_invoice_item_id',
        'remarks',
    ];

    protected $casts = [
        'mfg_date' => 'date',
        'expiry_date' => 'date',
        'mrp' => 'decimal:2',
        'rate' => 'decimal:2',
        'line_amount' => 'decimal:2',
        'gst_percent' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
    ];

    // Relationships
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoiceItem::class);
    }

    // Methods
    public function getQtyPendingAttribute(): int
    {
        return max(0, $this->qty_ordered - $this->qty_received - $this->qty_rejected);
    }

    public function calculateLineAmount(): void
    {
        $this->line_amount = ($this->qty_ordered + $this->qty_free) * $this->rate;
        $this->discount_amount = ($this->line_amount * $this->discount_percent) / 100;
        $this->gst_amount = (($this->line_amount - $this->discount_amount) * $this->gst_percent) / 100;
        $this->line_total = $this->line_amount - $this->discount_amount + $this->gst_amount;
    }

    public function isExpired(): bool
    {
        if (!$this->expiry_date) {
            return false;
        }
        return now()->toDateString() > $this->expiry_date->toDateString();
    }

    public function getQtyReceivedPercentAttribute(): float
    {
        return $this->qty_ordered > 0 
            ? ($this->qty_received / $this->qty_ordered) * 100 
            : 0;
    }
}
