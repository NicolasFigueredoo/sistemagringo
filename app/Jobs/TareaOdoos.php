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
        // en el hosting corre dentro del pedido web: que no la corte el navegador ni el tiempo máximo
        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        Progreso::avance('Arrancando…');
        $avance = fn (string $paso, int $hechos, int $total) => Progreso::avance($paso, $hechos, $total);
        try {
            $resumen = match ($this->tipo) {
                'sincronizar' => SincronizarOdoos::crear($avance)->ejecutar(),
                'porcentaje'  => (new PorcentajeVenta($avance))->aplicar($this->datos['porcentajes'], $this->datos['destinos'], (int) ($this->datos['redondeo'] ?? 0)),
            };
            Progreso::terminar($resumen);
        } catch (\Throwable $e) {
            report($e);
            Progreso::fallo(self::mensaje($e));
        }
    }

    /** Error legible: sin el HTML de las páginas de error de Odoo. */
    private static function mensaje(\Throwable $e): string
    {
        $m = $e->getMessage();
        if (str_contains($m, '429')) {
            return 'Odoo.com frenó los pedidos por exceso de velocidad (error 429) y no se liberó después de varios reintentos. '
                . 'Lo que ya se hizo quedó hecho: esperá unos minutos y tocá el botón de nuevo, sigue desde ahí.';
        }
        $m = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<(style|script|head)\b.*?</\1>#is', ' ', $m))));
        return mb_substr($m, 0, 400);
    }

    /** Si el proceso se corta (tiempo máximo, memoria), que la pantalla no quede esperando. */
    public function failed(?\Throwable $e): void
    {
        Progreso::fallo($e?->getMessage() ?: 'La tarea se cortó.');
    }
}
