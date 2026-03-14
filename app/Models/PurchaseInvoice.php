<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseInvoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'invoice_number', 'supplier_invoice_no', 'supplier_id',
        'invoice_date', 'received_date', 'due_days', 'transporter', 'lr_number', 'financial_year',
        'subtotal', 'discount_amount',
        'sgst_amount', 'cgst_amount', 'igst_amount',
        'round_off', 'total_amount', 'tax_type',
        'status', 'created_by', 'approved_by', 'approved_at',
        'notes',
    ];
        // Legacy import traceability — these columns are added at migration time by
        // erp:migrate-legacy-purchase-invoices when they do not yet exist.
        // They are declared in fillable so mass-assignment doesn't fail.


    protected $casts = [
        'invoice_date' => 'date',
        'received_date' => 'date',
        'approved_at' => 'datetime',
        'due_days' => 'integer',
        'subtotal' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function items() { return $this->hasMany(PurchaseInvoiceItem::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function approvedBy() { return $this->belongsTo(User::class, 'approved_by'); }

    public function scopeDraft($q) { return $q->where('status', 'draft'); }
    public function scopeApproved($q) { return $q->where('status', 'approved'); }
        public function scopeLegacy($q) { return $q->where('status', 'legacy'); }

        /** Returns true if this invoice is a read-only legacy archive record. */
        public function isLegacy(): bool { return $this->status === 'legacy'; }

    /**
     * Get current financial year string (Apr-Mar).
     */
    public static function currentFinancialYear(): string
    {
        $now = now();
        $year = $now->month >= 4 ? $now->year : $now->year - 1;
        return $year . '-' . substr($year + 1, -2);
    }
}
