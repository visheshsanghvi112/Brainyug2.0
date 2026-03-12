<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DistOrderItem extends Model
{
    protected $guarded = ['id'];
    protected $casts = [
        'expiry_date' => 'date',
    ];

    public function distOrder()
    {
        return $this->belongsTo(DistOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
