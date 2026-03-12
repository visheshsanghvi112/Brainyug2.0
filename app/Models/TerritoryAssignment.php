<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TerritoryAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'territory_type', // 'state', 'district', or 'city'
        'territory_id',
    ];

    // ─── Relationships ───

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the territory model (polymorphic-like but using enum).
     */
    public function territory()
    {
        return match ($this->territory_type) {
            'state' => $this->belongsTo(State::class, 'territory_id'),
            'district' => $this->belongsTo(District::class, 'territory_id'),
            'city' => $this->belongsTo(City::class, 'territory_id'),
            default => null,
        };
    }

    // ─── Scopes ───

    public function scopeStates($query)
    {
        return $query->where('territory_type', 'state');
    }

    public function scopeDistricts($query)
    {
        return $query->where('territory_type', 'district');
    }

    public function scopeCities($query)
    {
        return $query->where('territory_type', 'city');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
