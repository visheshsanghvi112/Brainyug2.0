<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Events\SaleCompleted;
use App\Listeners\TriggerReorderSuggestion;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        SaleCompleted::class => [
            TriggerReorderSuggestion::class,
        ],
    ];

    /**
     * Enable event discovery.
     *
     * @var bool
     */
    public function shouldDiscoverEvents(): bool
    {
        return true;
    }
}
