<?php

namespace App\Events;

use App\Models\StockAlert;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockAlertTriggered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public StockAlert $alert,
        public string $triggerPoint = ''
    ) {}

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('warehouse.alerts')];

        if ($this->alert->franchisee_id) {
            $channels[] = new PrivateChannel('franchisee.' . $this->alert->franchisee_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'stock.alert';
    }

    public function broadcastWith(): array
    {
        return [
            'product_id' => $this->alert->product_id,
            'location' => $this->alert->getLocationLabel(),
            'alert_type' => $this->alert->alert_type,
            'level' => $this->alert->alert_level,
            'current_qty' => $this->alert->current_qty,
            'threshold_qty' => $this->alert->threshold_qty,
            'triggered_at' => $this->alert->triggered_at,
            'trigger_source' => $this->alert->getTriggerLabel(),
        ];
    }
}
