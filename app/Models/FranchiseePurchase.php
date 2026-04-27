<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class FranchiseePurchase extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'purchase_date' => 'date',
        'received_date' => 'date',
        'approved_at' => 'datetime',
    ];

    // ──── Relationships ────
    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items()
    {
        return $this->hasMany(FranchiseePurchaseItem::class);
    }

    // ──── Scopes ────
    public function scopePending($query)
    {
        return $query->where('approval_status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('approval_status', 'approved');
    }

    public function scopeForFranchisee($query, Franchisee $franchisee)
    {
        return $query->where('franchisee_id', $franchisee->id);
    }

    public function scopeInFinancialYear($query, string $fy)
    {
        return $query->where('financial_year', $fy);
    }

    // ──── Helpers ────
    public function canApprove(): bool
    {
        return $this->approval_status === 'pending' && $this->status === 'draft';
    }

    public function canReject(): bool
    {
        return $this->approval_status === 'pending' && $this->status === 'draft';
    }

    public function totalTax(): float
    {
        return (float) $this->sgst_amount + (float) $this->cgst_amount + (float) $this->igst_amount;
    }

    public static function currentFinancialYear(?string $date = null): string
    {
        $dt = $date ? Carbon::parse($date) : now();
        $year = (int) $dt->format('n') >= 4 ? (int) $dt->format('Y') : (int) $dt->format('Y') - 1;
        $next = $year + 1;

        return sprintf('%d-%02d', $year, $next % 100);
    }

    public static function previewNextTransactionNumber(?string $fy = null): string
    {
        $financialYear = $fy ?: self::currentFinancialYear();
        $last = self::query()
            ->where('financial_year', $financialYear)
            ->latest('id')
            ->value('transaction_number');

        $nextNum = $last ? ((int) substr((string) $last, -4)) + 1 : 1;

        return 'FP-' . $financialYear . '-' . str_pad((string) $nextNum, 4, '0', STR_PAD_LEFT);
    }
}
