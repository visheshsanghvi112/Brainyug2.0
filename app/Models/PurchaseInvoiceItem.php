<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseInvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_invoice_id', 'product_id',
        'batch_no', 'expiry_date', 'mfg_date',
        'qty', 'free_qty', 'unit',
        'mrp', 'rate', 'discount_percent', 'discount_amount',
        'gst_percent', 'gst_amount', 'hsn_id',
        'taxable_amount', 'total_amount',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'mfg_date' => 'date',
        'qty' => 'decimal:2',
        'free_qty' => 'decimal:2',
        'mrp' => 'decimal:2',
        'rate' => 'decimal:2',
    ];

    public function purchaseInvoice() { return $this->belongsTo(PurchaseInvoice::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function hsn() { return $this->belongsTo(HsnMaster::class, 'hsn_id'); }
}
