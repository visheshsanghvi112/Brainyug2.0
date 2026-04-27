<?php

namespace App\Events;

use App\Models\FranchiseePurchase;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FranchiseePurchaseApproved implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public FranchiseePurchase $purchase,
        public User $approver
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('franchisee.' . $this->purchase->franchisee_id),
            new PrivateChannel('workflow.approvals'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'purchase.approved';
    }

    public function broadcastWith(): array
    {
        return [
            'transaction_number' => $this->purchase->transaction_number,
            'franchisee_id' => $this->purchase->franchisee_id,
            'approved_by' => $this->approver->name,
            'total_amount' => $this->purchase->total_amount,
            'approved_at' => $this->purchase->approved_at,
        ];
    }
}
