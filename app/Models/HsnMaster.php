<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HsnMaster extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'cgst_percent' => 'decimal:2',
        'sgst_percent' => 'decimal:2',
        'igst_percent' => 'decimal:2',
    ];

    /**
     * Append computed attributes to JSON output.
     */
    protected $appends = ['gst_rate'];

    /**
     * gst_rate = cgst_percent + sgst_percent (total GST for intra-state).
     * For inter-state use igst_percent. This accessor makes the controller
     * query for 'gst_rate' work without a DB column.
     */
    protected function gstRate(): Attribute
    {
        return Attribute::make(
            get: fn () => round((float) $this->cgst_percent + (float) $this->sgst_percent, 2),
        );
    }

    /**
     * Get the products assigned to this HSN code.
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'hsn_id');
    }
}
