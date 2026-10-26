<?php

namespace App\Http\Middleware;

use App\Support\Instancia;
use Closure;
use Illuminate\Http\Request;

/** Pantallas /viejo: trabajan contra el Odoo viejo y su base local. */
class OdooViejo
{
    public function handle(Request $request, Closure $next)
    {
        Instancia::usar(true);
        return $next($request);
    }
}
