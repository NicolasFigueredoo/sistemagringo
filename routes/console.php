<?php

use Illuminate\Support\Facades\Schedule;

// Catálogo de Odoo al día todas las mañanas (requiere el cron de Laravel: * * * * * php artisan schedule:run)
Schedule::command('odoo:sincronizar')->dailyAt('06:00');
