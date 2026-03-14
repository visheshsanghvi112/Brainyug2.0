<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerCreditCollection extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'collected_at' => 'date',
    ];

    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesInvoice()
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
