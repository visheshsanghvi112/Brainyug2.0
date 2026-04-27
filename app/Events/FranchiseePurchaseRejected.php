<?php

namespace App\Events;

use App\Models\FranchiseePurchase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FranchiseePurchaseRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public FranchiseePurchase $purchase,
        public string $reason
    ) {}
}
