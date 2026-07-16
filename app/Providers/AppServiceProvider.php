<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL; // 🚀 KINAHANGLAN I-IMPORT KINI NGA FACADE!

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
        // 🛡️ FORCE HTTPS CONFIGURATION PARA SA NGROK / SECURE CONNECTIONS
        // Aron mawala ang 'Mixed Content' ug mo-load ang tanang styles ug scripts gamit ang HTTPS
        if (str_contains(request()->headers->get('X-Forwarded-Host') ?? '', 'ngrok-free.dev') || request()->secure()) {
            URL::forceScheme('https');
        }

        // Gi-update gikan sa 'Admin' ngadto sa 'CDS Admin'
        Gate::before(function ($user, $ability) {
            return $user->hasRole('CDS Admin') ? true : null;
        });
    }
}
