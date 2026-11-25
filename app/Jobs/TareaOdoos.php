<?php

namespace App\Jobs;

use App\Services\PorcentajeVenta;
use App\Services\SincronizarOdoos;
use App\Support\Instancia;
use App\Support\Progreso;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tareas largas de la pantalla "Viejo → Nuevo", en segundo plano:
 *  - sincronizar: copia todo del Odoo viejo al nuevo
 *  - porcentaje: aplica % de venta por categoría (datos: porcentajes, destinos, redondeo)
 * El avance queda en Progreso para que la pantalla muestre la barra.
 */
class TareaOdoos implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(public string $tipo, public array $datos = []) {}

    public function handle(): void
    {
        // el proceso de la cola atiende a los dos Odoo: se parte siempre de la configuración principal
        Instancia::usar(false);
        $avance = fn (string $paso, int $hechos, int $total) => Progreso::avance($paso, $hechos, $total);
        try {
            $resumen = match ($this->tipo) {
                'sincronizar' => SincronizarOdoos::crear($avance)->ejecutar(),
                'porcentaje'  => (new PorcentajeVenta($avance))->aplicar($this->datos['porcentajes'], $this->datos['destinos'], (int) ($this->datos['redondeo'] ?? 0)),
            };
            Progreso::terminar($resumen);
        } catch (\Throwable $e) {
            report($e);
            Progreso::fallo($e->getMessage());
        }
    }

    /** Si el proceso se corta (tiempo máximo, memoria), que la pantalla no quede esperando. */
    public function failed(?\Throwable $e): void
    {
        Progreso::fallo($e?->getMessage() ?: 'La tarea se cortó.');
    }
}
