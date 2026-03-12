<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }

    public function salesInvoices()
    {
        return $this->hasMany(SalesInvoice::class);
    }

    // Total spend across all bills
    public function getTotalSpendAttribute(): float
    {
        return (float) $this->salesInvoices()->where('status', 'completed')->sum('total_amount');
    }
}
