<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierPaymentAllocation extends Model
{
    protected $fillable = [
        'supplier_id',
        'purchase_invoice_id',
        'financial_ledger_id',
        'allocation_date',
        'amount',
    ];

    protected $casts = [
        'allocation_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseInvoice()
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    public function financialLedger()
    {
        return $this->belongsTo(FinancialLedger::class);
    }
}
