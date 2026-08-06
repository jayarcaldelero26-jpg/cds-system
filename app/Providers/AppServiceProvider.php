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
        if (app()->environment('production')) {
            URL::forceScheme('https');
        }

        Gate::before(function ($user, $ability) {
            return $user->hasRole('CDS Admin') ? true : null;
        });
    }
}
