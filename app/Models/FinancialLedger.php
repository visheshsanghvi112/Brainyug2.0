<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialLedger extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'transaction_date' => 'date',
        'reversed_at' => 'datetime',
    ];

    public function ledgerable()
    {
        return $this->morphTo();
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public function supplierPaymentAllocations()
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function reversedBy()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function reversesFinancialLedger()
    {
        return $this->belongsTo(self::class, 'reverses_financial_ledger_id');
    }

    public function reversalLedgers()
    {
        return $this->hasMany(self::class, 'reverses_financial_ledger_id');
    }
}
