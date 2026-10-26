<?php

namespace App\Jobs;

use App\Models\Carga;
use App\Services\OdooClient;
use App\Services\Procesador;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * "Actualizar en Odoo" en segundo plano, de a tandas. Cada tanda queda registrada al terminar,
 * así que si algo se corta se retoma desde donde quedó (lo hace la pantalla si el job deja de avanzar).
 */
class AplicarCarga implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(public int $cargaId) {}

    public function handle(): void
    {
        $proc = new Procesador(OdooClient::desdeConfig());
        do {
            $carga = Carga::find($this->cargaId);
            if (!$carga || $carga->estado !== 'aplicando') return;
            $n = self::tanda($proc, $carga);
        } while ($n !== 0);
    }

    /**
     * Una tanda con candado, para que el job y la pantalla no escriban lo mismo a la vez.
     * Devuelve cuántos procesó, 0 si terminó, o null si otro proceso la está haciendo.
     */
    public static function tanda(Procesador $proc, Carga $carga): ?int
    {
        $lock = Cache::lock('aplicar-carga-' . $carga->id, 300);
        if (!$lock->block(30)) return null;
        try {
            $carga->refresh();
            return $carga->estado === 'aplicando' ? $proc->aplicarTanda($carga) : 0;
        } finally {
            $lock->release();
        }
    }
}
