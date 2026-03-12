<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryLedger extends Model
{
    use HasFactory;

    const UPDATED_AT = null; // Immutable — no updates

    protected $fillable = [
        'product_id', 'batch_no', 'expiry_date', 'mfg_date', 'mrp',
        'location_type', 'location_id',
        'transaction_type', 'reference_type', 'reference_id',
        'qty_in', 'qty_out', 'rate',
        'created_by', 'remarks',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'mfg_date' => 'date',
        'qty_in' => 'decimal:2',
        'qty_out' => 'decimal:2',
        'mrp' => 'decimal:2',
        'rate' => 'decimal:2',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }

    // ═══ Scopes ═══
    public function scopeAtLocation($q, string $type, int $id)
    {
        return $q->where('location_type', $type)->where('location_id', $id);
    }

    public function scopeForProduct($q, int $productId, ?string $batchNo = null)
    {
        $q->where('product_id', $productId);
        if ($batchNo) $q->where('batch_no', $batchNo);
        return $q;
    }

    public function scopeOfType($q, string $type)
    {
        return $q->where('transaction_type', $type);
    }
}
