<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Estado de la tarea de fondo entre los dos Odoo (sincronizar todo / aplicar porcentajes).
 * Hay una sola a la vez; la pantalla lo lee cada segundo y medio para mostrar la barra de avance.
 */
class Progreso
{
    private const CLAVE = 'odoos-tarea';
    /** Sin novedades en este tiempo, se da por cortada (el proceso de fondo se cerró). */
    private const VENCE = 600;

    private static float $ultimaEscritura = 0;

    public static function actual(): ?array
    {
        return Cache::get(self::CLAVE);
    }

    public static function enCurso(): bool
    {
        $t = self::actual();
        return $t && in_array($t['estado'], ['en_cola', 'corriendo'], true) && time() - ($t['actualizado'] ?? 0) < self::VENCE;
    }

    /** Arranca una tarea nueva. false si ya hay otra en curso. */
    public static function iniciar(string $tipo, string $titulo): bool
    {
        if (self::enCurso()) return false;
        Cache::forever(self::CLAVE, [
            'tipo' => $tipo, 'titulo' => $titulo, 'estado' => 'en_cola',
            'paso' => 'Esperando al proceso de fondo…', 'hechos' => 0, 'total' => 0,
            'inicio' => time(), 'actualizado' => time(), 'resumen' => null, 'error' => null,
        ]);
        return true;
    }

    /** Avance del paso actual. Escribe como mucho dos veces por segundo, salvo que cambie el paso o termine. */
    public static function avance(string $paso, int $hechos = 0, int $total = 0): void
    {
        $t = self::actual() ?? [];
        $ahora = microtime(true);
        $cambio = ($t['paso'] ?? null) !== $paso || $hechos >= $total;
        if (!$cambio && $ahora - self::$ultimaEscritura < 0.5) return;
        self::$ultimaEscritura = $ahora;
        Cache::forever(self::CLAVE, array_merge($t, [
            'estado' => 'corriendo', 'paso' => $paso, 'hechos' => $hechos, 'total' => $total, 'actualizado' => time(),
        ]));
    }

    public static function terminar(array $resumen): void
    {
        Cache::forever(self::CLAVE, array_merge(self::actual() ?? [], [
            'estado' => 'listo', 'resumen' => $resumen, 'fin' => time(), 'actualizado' => time(),
        ]));
    }

    public static function fallo(string $mensaje): void
    {
        Cache::forever(self::CLAVE, array_merge(self::actual() ?? [], [
            'estado' => 'error', 'error' => $mensaje, 'fin' => time(), 'actualizado' => time(),
        ]));
    }

    public static function descartar(): void
    {
        if (!self::enCurso()) Cache::forget(self::CLAVE);
    }
}
