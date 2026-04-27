<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'tds_percent' => 'decimal:2',
        'tds_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'is_tds_applicable' => 'boolean',
    ];

    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }

    public function expenseCategory()
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
