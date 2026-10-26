<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Odoo nuevo (todo el sistema) u Odoo viejo (solo facturas por foto, pantallas /viejo).
 * El viejo tiene su propia base local (catálogo, proveedores, cargas) y su propia conexión a Odoo;
 * usuarios, sesión, cola y caché son siempre los de la base principal.
 */
class Instancia
{
    private static ?array $original = null;

    public static function usar(bool $viejo): void
    {
        // la primera vez se guarda la configuración del Odoo nuevo para poder volver (el proceso de la cola atiende a los dos)
        self::$original ??= ['db' => config('database.default'), 'odoo' => config('odoo')];
        $principal = self::$original['db'];

        config([
            'database.principal'                     => $principal,
            'queue.connections.database.connection'  => $principal,
            'cache.stores.database.connection'       => $principal,
            'cache.stores.database.lock_connection'  => $principal,
        ]);
        $odoo = self::$original['odoo'];
        config(['odoo' => $viejo ? array_merge($odoo, $odoo['viejo'], ['es_viejo' => true]) : $odoo + ['es_viejo' => false]]);
        DB::setDefaultConnection($viejo ? 'sqlite_viejo' : $principal);
    }

    public static function esViejo(): bool
    {
        return (bool) config('odoo.es_viejo', false);
    }

    /** route() que se queda en la misma instancia: en el Odoo viejo usa las rutas "viejo.*". */
    public static function ruta(string $nombre, mixed $parametros = []): string
    {
        return route(self::nombreRuta($nombre), $parametros);
    }

    public static function nombreRuta(string $nombre): string
    {
        return self::esViejo() ? 'viejo.' . $nombre : $nombre;
    }
}
