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
}
