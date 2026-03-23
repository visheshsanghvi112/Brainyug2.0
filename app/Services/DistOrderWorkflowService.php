<?php

namespace App\Services;

use App\Models\DistOrder;
use App\Models\User;
use DomainException;

class DistOrderWorkflowService
{
    /**
     * These reflect the statuses currently persisted in dist_orders.
     * The goal is to centralize transitions now, then evolve names later
     * without scattering status checks across controllers.
     */
    private const TRANSITIONS = [
        'pending' => ['accepted', 'rejected', 'cancelled'],
        'accepted' => ['allocated', 'rejected', 'cancelled'],
        'allocated' => ['dispatched', 'rejected', 'cancelled'],
        'dispatched' => ['delivered', 'cancelled'],
        'delivered' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    private const LABELS = [
        'pending' => 'Submitted',
        'accepted' => 'Commercially Approved',
        'allocated' => 'Allocated',
        'dispatched' => 'Dispatched',
        'delivered' => 'Delivered',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ];

    public function allowedTransitions(string $fromStatus): array
    {
        return self::TRANSITIONS[$fromStatus] ?? [];
    }

    public function canTransition(string $fromStatus, string $toStatus): bool
    {
        return in_array($toStatus, $this->allowedTransitions($fromStatus), true);
    }

    public function assertTransition(string $fromStatus, string $toStatus): void
    {
        if (!$this->canTransition($fromStatus, $toStatus)) {
            throw new DomainException("Order cannot transition from {$fromStatus} to {$toStatus}.");
        }
    }

    public function transition(
        DistOrder $order,
        string $toStatus,
        ?User $actor = null,
        array $attributes = [],
        ?string $note = null,
        array $meta = []
    ): DistOrder {
        $fromStatus = (string) $order->status;
        $this->assertTransition($fromStatus, $toStatus);

        $order->fill(array_merge($attributes, ['status' => $toStatus]));
        $order->save();

        $order->statusLogs()->create([
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_user_id' => $actor?->id,
            'note' => $note,
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);

        return $order;
    }

    public function logInitialSubmission(DistOrder $order, ?User $actor = null, array $meta = []): void
    {
        $order->statusLogs()->create([
            'from_status' => null,
            'to_status' => (string) $order->status,
            'actor_user_id' => $actor?->id,
            'note' => 'Order submitted by franchisee.',
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);
    }

    public function labelFor(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst($status);
    }
}
