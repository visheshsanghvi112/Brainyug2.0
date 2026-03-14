<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DistOrder extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];
    protected $casts = [
        'dispatch_date' => 'date',
        'accepted_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(DistOrderItem::class);
    }

    public function acceptedBy()
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function dispatchedBy()
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function commissions()
    {
        return $this->hasMany(Commission::class);
    }

    public function payments()
    {
        return $this->hasMany(DistOrderPayment::class)->latest('id');
    }

    /**
     * Replaces the old sequence logic.
     */
    public static function generateOrderNumber()
    {
        $year = now()->format('Y');
        $month = now()->format('m');
        $lastOrder = static::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->orderByDesc('id')
            ->first();

        // ORD-2026-03-0001
        $nextId = $lastOrder ? ((int) substr($lastOrder->order_number, -4)) + 1 : 1;
        return 'ORD-' . $year . '-' . $month . '-' . str_pad($nextId, 4, '0', STR_PAD_LEFT);
    }
}
