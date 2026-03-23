<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesQuotation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quotation_date' => 'datetime',
        'sub_total' => 'decimal:2',
        'total_discount_amount' => 'decimal:2',
        'total_tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function salesInvoice()
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    public function items()
    {
        return $this->hasMany(SalesQuotationItem::class);
    }
}
