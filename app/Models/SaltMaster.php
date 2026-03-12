<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaltMaster extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected $casts = [
        'is_narcotic' => 'boolean',
        'schedule_h' => 'boolean',
        'schedule_h1' => 'boolean',
    ];

    /**
     * Get the products associated with this salt composition.
     */
    public function products()
    {
        return $this->hasMany(Product::class, 'salt_id');
    }
}
