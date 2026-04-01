<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            $request = request();

            // Keep generated asset URLs aligned with the actual web root, even when
            // the app is served from a subdirectory such as XAMPP's /project/public path.
            URL::forceRootUrl($request->root());
        }

        Vite::prefetch(concurrency: 3);
    }
}
