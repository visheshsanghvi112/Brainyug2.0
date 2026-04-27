<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FranchiseePurchaseItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'mfg_date' => 'date',
        'expiry_date' => 'date',
    ];

    // ──── Relationships ────
    public function purchase()
    {
        return $this->belongsTo(FranchiseePurchase::class, 'franchisee_purchase_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function hsn()
    {
        return $this->belongsTo(HsnMaster::class, 'hsn_id');
    }

    // ──── Helpers ────
    public function totalQty(): float
    {
        return (float) $this->qty + (float) $this->free_qty;
    }

    public function taxableAmountCalculated(): float
    {
        $unitTotal = ((float) $this->rate - (float) $this->discount_amount) * (float) $this->qty;
        return $unitTotal;
    }

    public function isExpiringSoon(): bool
    {
        if (!$this->expiry_date) {
            return false;
        }
        return $this->expiry_date->isPast() || $this->expiry_date->diffInDays(now()) <= 30;
    }
}
