<?php

namespace App\Providers;

use App\Http\Middleware\OdooViejo;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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
        // los pedidos de Livewire de una pantalla /viejo también van contra el Odoo viejo
        Livewire::addPersistentMiddleware([OdooViejo::class]);
    }
}
