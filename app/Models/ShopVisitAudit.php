<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopVisitAudit extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'visit_date' => 'date',
        'photos' => 'array',
        'checklist' => 'array',
    ];

    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }

    public function auditor()
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }
}
