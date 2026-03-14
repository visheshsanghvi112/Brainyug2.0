<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RackArea extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public function section()
    {
        return $this->belongsTo(RackSection::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
