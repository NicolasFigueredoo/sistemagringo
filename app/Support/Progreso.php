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
    private const VENCE = 360;

    private static float $ultimaEscritura = 0;

    public static function actual(): ?array
    {
        $t = Cache::get(self::CLAVE);
        // sin novedades hace rato: el hosting cortó el proceso. Se muestra como error (se puede volver a tocar el botón).
        if ($t && in_array($t['estado'], ['en_cola', 'corriendo'], true) && time() - ($t['actualizado'] ?? 0) >= self::VENCE) {
            $t = array_merge($t, ['estado' => 'error', 'error' => 'Se cortó sin terminar (el servidor cortó el proceso). Lo que ya se hizo quedó hecho: tocá el botón de nuevo y sigue desde ahí.']);
        }
        return $t;
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

    /**
     * Arranca un proceso de fondo que atiende la cola y se cierra solo al vaciarla.
     * Así la tarea corre aunque la ventana "Tio Gringo - tareas" no esté abierta (si está, no molesta: una sola la toma).
     * Como mucho uno cada 30 segundos.
     */
    public static function lanzarProceso(): void
    {
        if (!Cache::add('odoos-proceso-lanzado', time(), 30)) return;
        $php = self::php();
        $artisan = base_path('artisan');
        $log = storage_path('logs/tareas.log');
        $args = 'queue:work --stop-when-empty --timeout=3600 --tries=1 --sleep=1';
        try {
            if (!function_exists('popen') || !function_exists('exec')) return;
            if (PHP_OS_FAMILY === 'Windows') {
                pclose(popen("start \"\" /B \"$php\" \"$artisan\" $args >> \"$log\" 2>&1", 'r'));
            } else {
                exec(sprintf('nohup %s %s %s >> %s 2>&1 &', escapeshellarg($php), escapeshellarg($artisan), $args, escapeshellarg($log)));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** php.exe de la línea de comandos (si el sitio corre con php-cgi, el que está al lado). */
    private static function php(): string
    {
        $bin = PHP_BINARY ?: 'php';
        if (!str_contains(strtolower(basename($bin)), 'cgi') && !str_contains(strtolower(basename($bin)), 'fpm')) return $bin;
        $cli = dirname($bin) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        return is_file($cli) ? $cli : 'php';
    }

    public static function descartar(): void
    {
        if (!self::enCurso()) Cache::forget(self::CLAVE);
    }
}
