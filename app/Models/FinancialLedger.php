<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialLedger extends Model
{
    protected $guarded = ['id'];

    public function ledgerable()
    {
        return $this->morphTo();
    }

    public function reference()
    {
        return $this->morphTo();
    }
}
