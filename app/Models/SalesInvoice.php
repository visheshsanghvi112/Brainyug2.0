<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesInvoice extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'date_time' => 'datetime',
        'sub_total' => 'decimal:2',
        'total_discount_amount' => 'decimal:2',
        'total_tax_amount' => 'decimal:2',
        'other_charges' => 'decimal:2',
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

    public function items()
    {
        return $this->hasMany(SalesInvoiceItem::class);
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }

    public function creditCollections()
    {
        return $this->hasMany(CustomerCreditCollection::class);
    }
}
