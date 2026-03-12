<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class B2bCartItem extends Model
{
    protected $guarded = ['id'];

    public function cart()
    {
        return $this->belongsTo(B2bCart::class, 'b2b_cart_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
