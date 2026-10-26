<?php

namespace App\Services;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Baja la lista de precios que el proveedor publica en su web.
 * Si el sitio pide usuario (config services.listas, por dominio) primero inicia sesión con la cuenta del .env.
 */
class DescargaLista
{
    /** @return array{ruta:string, ext:string, nombre:string} ruta absoluta del archivo bajado */
    public static function bajar(string $url): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) throw new RuntimeException('La dirección tiene que empezar con http:// o https://');

        $host = preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        $jar = new CookieJar();
        $http = fn () => Http::withOptions(['cookies' => $jar])->withUserAgent('Mozilla/5.0')->timeout(120);

        // el dominio tiene puntos: no se puede buscar con config("services.listas.$host")
        if ($sitio = config('services.listas')[$host] ?? null) {
            self::entrar($http, $url, $host, $sitio);
        }

        $r = $http()->get($url);
        if (!$r->successful()) throw new RuntimeException("La web respondió con error {$r->status()}.");
        $cuerpo = $r->body();

        $ext = self::extension($cuerpo, (string) $r->header('Content-Disposition'));
        if ($ext === null) {
            throw new RuntimeException($sitio
                ? 'La web devolvió una página en vez de la lista: puede que no haya aceptado el usuario.'
                : 'La web devolvió una página en vez de un archivo. Si pide iniciar sesión, hay que cargar la cuenta.');
        }

        $nombre = $host . ' ' . now()->format('d-m-Y') . '.' . $ext;
        $relativa = 'listas/' . now()->format('Ymd-His') . '-' . preg_replace('/[^a-z0-9]+/', '-', $host) . '.' . $ext;
        Storage::disk('local')->put($relativa, $cuerpo);

        return ['ruta' => Storage::disk('local')->path($relativa), 'ext' => $ext, 'nombre' => $nombre];
    }

    private static function entrar(callable $http, string $url, string $host, array $sitio): void
    {
        if (empty($sitio['email']) || empty($sitio['password'])) {
            throw new RuntimeException("Falta la cuenta de $host en el .env ({$sitio['env']}_EMAIL y {$sitio['env']}_PASSWORD).");
        }
        $login = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . $sitio['login'];
        $http()->get($login);   // cookie de sesión
        $r = $http()->asForm()->post($login, ['email' => $sitio['email'], 'password' => $sitio['password']]);
        // si vuelve a mostrar el formulario es que no entró
        if (!$r->successful() || str_contains($r->body(), 'name="password"')) {
            // el motivo que da la web ("El email ingresado es invalido")
            $motivo = preg_match('/class="[^"]*(?:error|alert)[^"]*"[^>]*>\s*([^<]{3,150})/i', $r->body(), $m) ? ' (la web dice: "' . trim($m[1]) . '")' : '';
            throw new RuntimeException("No se pudo entrar a $host$motivo: revisá {$sitio['env']}_EMAIL y {$sitio['env']}_PASSWORD en el .env.");
        }
    }

    /** Formato del archivo por su contenido (el nombre que manda la web no siempre es confiable). Null = es una página. */
    private static function extension(string $cuerpo, string $disposicion): ?string
    {
        $inicio = substr($cuerpo, 0, 8);
        return match (true) {
            str_starts_with($inicio, '%PDF')             => 'pdf',
            str_starts_with($inicio, "PK\x03\x04")       => preg_match('/\.ods\b/i', $disposicion) ? 'ods' : 'xlsx',
            $inicio === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" => 'xls',
            (bool) preg_match('/^\s*</', $cuerpo)        => null,   // HTML: login o error
            default                                      => 'csv',
        };
    }
}
