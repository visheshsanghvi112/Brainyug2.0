<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'images' => 'json',
        'is_active' => 'boolean',
        'is_loose_sellable' => 'boolean',
        'is_banned' => 'boolean'
    ];

    // Relationships
    public function company()
    {
        return $this->belongsTo(CompanyMaster::class, 'company_id');
    }

    public function category()
    {
        return $this->belongsTo(ItemCategory::class, 'category_id');
    }

    public function salt()
    {
        return $this->belongsTo(SaltMaster::class, 'salt_id');
    }

    public function hsn()
    {
        return $this->belongsTo(HsnMaster::class, 'hsn_id');
    }

    public function boxSize()
    {
        return $this->belongsTo(BoxSize::class, 'box_size_id');
    }

    public function rackSection()
    {
        return $this->belongsTo(RackSection::class, 'rack_section_id');
    }

    public function rackArea()
    {
        return $this->belongsTo(RackArea::class, 'rack_area_id');
    }

    public function inventoryLedgers()
    {
        return $this->hasMany(InventoryLedger::class, 'product_id');
    }

    public function orderItems()
    {
        return $this->hasMany(DistOrderItem::class, 'product_id');
    }

    public function b2bCartItems()
    {
        return $this->hasMany(B2bCartItem::class, 'product_id');
    }

    public function scopeVisibleForFranchise($query)
    {
        return $query
            ->where('is_active', true)
            ->where('hide', false)
            ->where('is_banned', false);
    }

    public function scopeSearchByTerm($query, string $term)
    {
        $term = trim($term);

        return $query->where(function ($nested) use ($term) {
            $nested->where('product_name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhere('barcode', 'like', "%{$term}%")
                ->orWhere('product_code', 'like', "%{$term}%")
                ->orWhere('fast_search_index', 'like', "%{$term}%");
        });
    }

    public function franchiseRate(): float
    {
        foreach ([$this->rate_a, $this->ptr, $this->pts, $this->mrp] as $candidate) {
            if ($candidate !== null && (float) $candidate > 0) {
                return round((float) $candidate, 2);
            }
        }

        return 0.0;
    }

    public function gstPercent(): float
    {
        $sgst = (float) ($this->sgst ?? 0);
        $cgst = (float) ($this->cgst ?? 0);
        $igst = (float) ($this->igst ?? 0);

        if (($sgst + $cgst) <= 0 && $igst <= 0) {
            $hsn = $this->relationLoaded('hsn') ? $this->hsn : ($this->hsn_id ? $this->hsn()->first() : null);

            if ($hsn) {
                $sgst = (float) ($hsn->sgst_percent ?? 0);
                $cgst = (float) ($hsn->cgst_percent ?? 0);
                $igst = (float) ($hsn->igst_percent ?? 0);
            }
        }

        return round(($sgst + $cgst) > 0 ? ($sgst + $cgst) : $igst, 2);
    }
}
