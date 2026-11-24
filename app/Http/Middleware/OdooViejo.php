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
        // sistema nuevo en pausa: sus pantallas no se abren, se vuelve al inicio
        if (Instancia::nuevoPausado()) return redirect()->route('subir');
        Instancia::usar(true);
        return $next($request);
    }
}
