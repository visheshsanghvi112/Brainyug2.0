<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosHold extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'customer_payload' => 'array',
        'doctor_payload' => 'array',
        'items_payload' => 'array',
        'meta_payload' => 'array',
        'is_locked' => 'boolean',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'held_at' => 'datetime',
        'lock_expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }

    public function lockOwner()
    {
        return $this->belongsTo(User::class, 'lock_owner_user_id');
    }

    public function salesInvoice()
    {
        return $this->belongsTo(SalesInvoice::class);
    }
}
