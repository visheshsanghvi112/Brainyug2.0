<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FranchiseeStaff extends Model
{
    use HasFactory;

    protected $table = 'franchisee_staff';

    protected $fillable = [
        'franchisee_id',
        'user_id',
        'designation',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ─── Relationships ───

    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
