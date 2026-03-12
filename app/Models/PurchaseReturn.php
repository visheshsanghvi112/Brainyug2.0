<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseReturn extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'return_number', 'supplier_id', 'purchase_invoice_id',
        'return_date', 'financial_year',
        'subtotal', 'sgst_amount', 'cgst_amount', 'igst_amount', 'total_amount',
        'status', 'reason', 'created_by', 'approved_by',
    ];

    protected $casts = ['return_date' => 'date', 'total_amount' => 'decimal:2'];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function purchaseInvoice() { return $this->belongsTo(PurchaseInvoice::class); }
    public function items() { return $this->hasMany(PurchaseReturnItem::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
}
