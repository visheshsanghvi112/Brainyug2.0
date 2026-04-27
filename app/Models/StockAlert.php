<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAlert extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'triggered_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'expiry_date' => 'date',
    ];

    // ──── Relationships ────
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function franchisee()
    {
        return $this->belongsTo(Franchisee::class);
    }

    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    // ──── Scopes ────
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCritical($query)
    {
        return $query->where('alert_level', 'critical');
    }

    public function scopeForFranchisee($query, Franchisee $franchisee)
    {
        return $query->where('franchisee_id', $franchisee->id);
    }

    public function scopeForHo($query)
    {
        return $query->whereNull('franchisee_id');
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('triggered_at', '>=', now()->subDays($days));
    }

    public function scopeUnacknowledged($query)
    {
        return $query->where('status', 'pending')->whereNull('acknowledged_at');
    }

    // ──── Helpers ────
    public function isExpireAlert(): bool
    {
        return $this->alert_type === 'expiry';
    }

    public function isThresholdAlert(): bool
    {
        return $this->alert_type === 'threshold';
    }

    public function isCritical(): bool
    {
        return $this->alert_level === 'critical';
    }

    public function isAcknowledged(): bool
    {
        return !is_null($this->acknowledged_at);
    }

    public function getLocationLabel(): string
    {
        if ($this->franchisee_id) {
            $franchiseeName = $this->franchisee?->shop_name ?? 'Unknown Franchisee';
            return "Franchisee: {$franchiseeName}";
        }
        return 'HO Warehouse';
    }

    public function getTriggerLabel(): string
    {
        return match ($this->trigger_source) {
            'purchase_approved' => 'HO Purchase Approved',
            'dispatch_completed' => 'Dispatch Completed',
            'franchisee_purchase_approved' => 'Franchisee Outside Purchase Approved',
            'scheduled_check' => 'Scheduled Stock Check',
            'expiry_check' => 'Scheduled Expiry Check',
            default => ucwords(str_replace('_', ' ', $this->trigger_source ?? 'Unknown')),
        };
    }
}
