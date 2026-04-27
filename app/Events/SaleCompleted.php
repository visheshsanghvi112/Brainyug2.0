<?php

namespace App\Events;

use App\Models\SalesInvoice;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SaleCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SalesInvoice $salesInvoice,
        public int $franchiseeId,
    ) {}
}
