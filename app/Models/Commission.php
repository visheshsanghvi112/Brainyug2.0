<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Commission extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'commission_percent' => 'decimal:2',
        'gross_commission' => 'decimal:2',
        'tds_percent' => 'decimal:2',
        'tds_amount' => 'decimal:2',
        'net_payable' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function distOrder()
    {
        return $this->belongsTo(DistOrder::class);
    }

    public function reversedBy()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function reverses()
    {
        return $this->belongsTo(self::class, 'reverses_commission_id');
    }
}
