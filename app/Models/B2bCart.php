<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class B2bCart extends Model
{
    protected $guarded = ['id'];

    public function items()
    {
        return $this->hasMany(B2bCartItem::class);
    }

    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
