<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOverrideAudit extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'approval_snapshot' => 'array',
        'checkout_snapshot' => 'array',
        'approved_at' => 'datetime',
        'consumed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function salesInvoice()
    {
        return $this->belongsTo(SalesInvoice::class);
    }
}
