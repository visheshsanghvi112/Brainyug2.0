<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RackSection extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public function allAreas()
    {
        return $this->hasMany(RackArea::class)->orderBy('name');
    }

    public function areas()
    {
        return $this->hasMany(RackArea::class)->where('status', true)->orderBy('name');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
