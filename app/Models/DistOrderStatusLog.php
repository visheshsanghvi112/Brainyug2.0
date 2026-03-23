<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DistOrderStatusLog extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function distOrder()
    {
        return $this->belongsTo(DistOrder::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
