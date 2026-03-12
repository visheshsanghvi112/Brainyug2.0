<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Doctor extends Model
{
    protected $guarded = ['id'];

    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }
}
