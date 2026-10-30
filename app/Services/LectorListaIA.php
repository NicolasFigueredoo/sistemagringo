<?php

namespace App\Services;

use App\Models\LecturaFactura;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lee una lista de precios en PDF con la API de Claude, a partir del TEXTO que saca pdftotext (no de imágenes: es mucho más barato).
 * Sirve para los PDF con productos en varios renglones, varias columnas de precio, títulos de rubro, etc.
 * Devuelve las filas en el mismo formato que LectorLista, con encabezado, para que la pantalla de columnas funcione igual.
 */
class LectorListaIA
{
    /** caracteres de texto por llamada (unas 15 a 25 páginas) */
    private const TRAMO = 40000;
    /** en la pantalla de carga cada tramo es un pedido aparte: tiene que responder antes de que el servidor corte (~60 s) */
    public const TRAMO_WEB = 6000;
    /** tramos que se mandan juntos (en paralelo) en cada pedido de la pantalla de carga */
    public const A_LA_VEZ = 10;

    private const INSTRUCCIONES = <<<'TXT'
        Sos el asistente de una ferretería en Argentina. Te paso el texto (extraído de un PDF, con la disposición original en columnas)
        de un tramo de la lista de precios de un proveedor. Pasá cada producto a la lista "items" (claves cortas para ahorrar texto):
        - b (código de barras): el código EAN/UPC (8 a 14 dígitos) si figura; si no, null. Un código de 8+ dígitos en la columna "Código"/"Cód." es un EAN.
        - c (código): el código o SKU del proveedor si no es un EAN (ej. "TER320", "RH-2402", "PY 10 CTOPE", "8670C"); si no hay, null.
        - d (descripción): la descripción completa del producto. Si está partida en varias líneas, unila. Podés agregar la marca al final si figura aparte.
        - p (precios): TODOS los importes de precio del renglón, en el orden de las columnas de precio de la lista, como números
          (formato argentino: "1.234,56" = 1234.56; "$193.750" = 193750). No incluyas stock, cantidades por caja ni medidas.
          Si un importe quedó cortado en dos líneas ("107.903,2" y abajo "5") es 107903.25.

        columnas_precio: los nombres de las columnas de precio en ese orden (ej. ["VENTA + iva", "PVP+iva"] o ["Efectivo", "Tarjeta", "Lista 3", "Lista 4"]).
        indice_costo: posición (desde 0) de la columna que paga la ferretería al proveedor: precio de lista, venta, efectivo o costo.
          Nunca PVP, precio sugerido, público ni e-commerce.
        precios_con_iva: true si la columna de costo incluye IVA (dice "+ iva", "IVA incluido", "final"); si no, false.

        Salteá títulos de rubro, encabezados, pies de página, textos de publicidad y renglones sin ningún precio.
        No inventes nada: si un dato no se lee, poné null. No repitas productos.
        TXT;

    public function __construct(private string $apiKey, private string $modelo) {}

    public static function disponible(): bool
    {
        return (bool) config('services.anthropic.key');
    }

    public static function desdeConfig(): self
    {
        $key = config('services.anthropic.key');
        if (!$key) throw new RuntimeException('Falta ANTHROPIC_API_KEY en el archivo .env.');
        return new self($key, config('services.anthropic.model_listas') ?: config('services.anthropic.model'));
    }

    /**
     * @param string $texto salida de pdftotext -layout (páginas separadas por \f)
     * @return array{filas:array, columnas:array, precios_con_iva:bool, columna_costo:?string}
     */
    public function leer(string $texto, ?string $control = null): array
    {
        $respuestas = [];
        foreach (array_chunk(self::tramos($texto), self::A_LA_VEZ) as $grupo) {
            array_push($respuestas, ...$this->pedirVarios($grupo, $control));
        }
        return self::aFilas($respuestas);
    }

    /** Agrupa las páginas en tramos de hasta TRAMO caracteres, sin cortar páginas. */
    public static function tramos(string $texto, int $max = self::TRAMO): array
    {
        $tramos = [];
        $actual = '';
        foreach (explode("\f", $texto) as $pagina) {
            // menos espacios = menos tokens; 3 espacios alcanzan para ver las columnas
            $pagina = trim(preg_replace('/ {3,}/', '   ', $pagina));
            if ($pagina === '') continue;
            if ($actual !== '' && mb_strlen($actual) + mb_strlen($pagina) > $max) {
                $tramos[] = $actual;
                $actual = '';
            }
            $actual .= ($actual === '' ? '' : "\n\n--- página ---\n\n") . $pagina;
        }
        if ($actual !== '') $tramos[] = $actual;
        return $tramos;
    }

    public function pedir(string $tramo): array
    {
        return $this->pedirVarios([$tramo])[0];
    }

    /** Varios tramos a la vez (llamadas en paralelo, tardan lo que el más lento). Respuestas en el mismo orden. */
    public function pedirVarios(array $tramos, ?string $control = null): array
    {
        return array_map(fn ($r) => $r instanceof \Throwable ? throw $r : $r, $this->intentarVarios(array_values($tramos), $control));
    }

    /**
     * Como pedirVarios, pero el tramo que falla vuelve como excepción (para reintentar solo ese). Mantiene las claves.
     * $control: texto del PDF entero en orden (-raw) para verificar los precios; si no hay, se verifica contra el tramo.
     */
    public function intentarVarios(array $tramos, ?string $control = null): array
    {
        // as(): el pool devuelve las respuestas con la misma clave que el tramo
        $resps = Http::pool(fn (Pool $pool) => array_map(fn ($i, $tramo) => $pool->as((string) $i)
            ->withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'anthropic-beta'    => 'server-side-fallback-2026-07-01',
            ])
            ->timeout(300)
            ->post('https://api.anthropic.com/v1/messages', [
                'model'         => $this->modelo,
                'max_tokens'    => 32000,
                'system'        => self::INSTRUCCIONES,
                'messages'      => [['role' => 'user', 'content' => [['type' => 'text', 'text' => "Lista de precios:\n\n" . $tramo]]]],
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::esquema()]],
                'fallbacks'     => 'default',
            ]), array_keys($tramos), $tramos));

        $res = [];
        foreach ($resps as $i => $resp) {
            try {
                $res[$i] = self::verificarPrecios($this->respuesta($resp), $control ?? $tramos[$i]);
            } catch (\Throwable $e) {
                $res[$i] = $e;
            }
        }
        return $res;
    }

    /**
     * La IA a veces le pone a un producto el precio de otro (listas con código, descripción, marca e importes en
     * renglones separados). Los precios tienen que salir del bloque del producto en el PDF: desde el renglón de su
     * código hasta el del código siguiente. Si no coinciden, se usan los importes de ese bloque.
     */
    public static function verificarPrecios(array $datos, string $tramo): array
    {
        $lineas = preg_split('/\r\n|\n|\r/', $tramo);
        $items = $datos['items'] ?? [];

        // renglón donde está el código de cada producto (si el código aparece en un solo renglón)
        $desde = [];
        foreach ($items as $k => $it) {
            $i = self::lineaDelCodigo($lineas, (string) ($it['codigo_barras'] ?? ''))
                ?? self::lineaDelCodigo($lineas, (string) ($it['codigo'] ?? ''));
            if ($i !== null) $desde[$k] = $i;
        }
        $inicios = array_values(array_unique($desde));
        sort($inicios);

        foreach ($desde as $k => $i) {
            $siguientes = array_filter($inicios, fn ($j) => $j > $i);
            $hasta = min($siguientes ? min($siguientes) : count($lineas), $i + 8);
            // lo que precede al código en su renglón ("Cód:"): el bloque termina donde empieza el producto siguiente
            $prefijo = trim(mb_substr($lineas[$i], 0, (int) mb_stripos($lineas[$i], self::codigoDe($items[$k]))));
            if ($prefijo !== '' && mb_strlen($prefijo) <= 15) {
                for ($j = $i + 1; $j < $hasta; $j++) {
                    if (str_starts_with(ltrim($lineas[$j]), $prefijo)) { $hasta = $j; break; }
                }
            }
            $bloque = implode("\n", array_slice($lineas, $i, $hasta - $i));
            // importe cortado en dos renglones: "107.903,2" y abajo "5" es 107.903,25
            $bloque = preg_replace('/(\d,\d)[ \t]*\n[ \t]*(\d)(?![\d.,])/', '$1$2', $bloque);

            // importes con centavos ("5.270,00", "$ 1.234,5"): así no se confunden con stock, unidades o el código
            preg_match_all('/(?<![\d.,])\d{1,3}(?:\.\d{3})*,\d{1,2}(?![\d,])|(?<![\d.,])\d+,\d{1,2}(?![\d,])/', $bloque, $m);
            $enBloque = array_map(fn ($v) => (float) str_replace(['.', ','], ['', '.'], $v), $m[0]);
            if (!$enBloque || count($enBloque) > 8) continue;

            $precios = array_values(array_filter((array) ($items[$k]['precios'] ?? []), 'is_numeric'));
            $estan = $precios && !array_filter($precios, fn ($p) => !array_filter($enBloque, fn ($r) => abs($r - $p) < 0.015));
            if (!$estan) $datos['items'][$k]['precios'] = $enBloque;
        }
        return $datos;
    }

    /** Índice del único renglón del texto que tiene el código como palabra suelta (si está en varios, no se sabe cuál es). */
    private static function lineaDelCodigo(array $lineas, string $codigo): ?int
    {
        $codigo = trim($codigo);
        if (mb_strlen($codigo) < 4) return null;
        $patron = '/(?<![\p{L}\p{N}])' . preg_quote($codigo, '/') . '(?![\p{L}\p{N}])/iu';
        // stripos primero: el texto de control es el PDF entero
        $hay = array_keys(array_filter($lineas, fn ($l) => stripos($l, $codigo) !== false && preg_match($patron, $l)));
        return count($hay) === 1 ? $hay[0] : null;
    }

    /** El código con el que se encontró el renglón del producto (barras o, si no, el del proveedor). */
    private static function codigoDe(array $it): string
    {
        $b = trim((string) ($it['codigo_barras'] ?? ''));
        return mb_strlen($b) >= 4 ? $b : trim((string) ($it['codigo'] ?? ''));
    }

    private function respuesta($resp): array
    {
        if ($resp instanceof \Throwable) throw new RuntimeException('Sin conexión con la IA: ' . $resp->getMessage());
        if (!$resp->successful()) throw new RuntimeException('La IA respondió con error ' . $resp->status() . ': ' . ($resp->json('error.message') ?? $resp->body()));

        LecturaFactura::create([
            'user_id'        => auth()->id(),
            'tipo'           => 'lista',
            'fotos'          => 0,
            'modelo'         => $resp->json('model'),
            'tokens_entrada' => (int) $resp->json('usage.input_tokens'),
            'tokens_salida'  => (int) $resp->json('usage.output_tokens'),
        ]);

        if ($resp->json('stop_reason') === 'refusal') throw new RuntimeException('La IA no quiso leer la lista.');
        if ($resp->json('stop_reason') === 'max_tokens') throw new RuntimeException('Un tramo de la lista es demasiado largo para la IA.');

        foreach ($resp->json('content') ?? [] as $b) {
            if (($b['type'] ?? '') === 'text') {
                $datos = json_decode($b['text'], true);
                if (!is_array($datos)) continue;
                // claves cortas de la respuesta -> las de siempre
                $datos['items'] = array_map(fn ($it) => [
                    'codigo_barras' => $it['b'] ?? null,
                    'codigo'        => $it['c'] ?? null,
                    'descripcion'   => $it['d'] ?? '',
                    'precios'       => $it['p'] ?? [],
                ], $datos['items'] ?? []);
                return $datos;
            }
        }
        throw new RuntimeException('No se pudo leer la respuesta de la IA.');
    }

    /**
     * Junta las respuestas de todos los tramos en filas [barcode, codigo, descripcion, precio1, precio2...],
     * con una fila de encabezado. La columna de costo la elige la IA en el primer tramo que trae precios.
     */
    public static function aFilas(array $respuestas): array
    {
        $nombres = [];
        $indice = 0;
        $conIva = false;
        foreach ($respuestas as $r) {
            if (!empty($r['columnas_precio'])) {
                $nombres = array_values(array_map('strval', $r['columnas_precio']));
                $indice = max(0, (int) ($r['indice_costo'] ?? 0));
                $conIva = (bool) ($r['precios_con_iva'] ?? false);
                break;
            }
        }

        $txt = fn ($v) => ($v = trim((string) $v)) === '' ? '' : $v;
        $filas = [];
        $vistos = [];
        $nPrecios = count($nombres);
        foreach ($respuestas as $r) {
            foreach ($r['items'] ?? [] as $it) {
                $precios = array_values(array_filter((array) ($it['precios'] ?? []), fn ($p) => is_numeric($p)));
                if (!$precios) continue;
                $barcode = preg_replace('/\D/', '', (string) ($it['codigo_barras'] ?? ''));
                $barcode = strlen($barcode) >= 8 ? $barcode : '';
                $codigo = $txt($it['codigo'] ?? '');
                $desc = $txt($it['descripcion'] ?? '');
                if ($desc === '' && $codigo === '' && $barcode === '') continue;

                // el mismo producto partido entre dos tramos
                $clave = $barcode . '|' . $codigo . '|' . $desc;
                if (isset($vistos[$clave])) continue;
                $vistos[$clave] = true;

                $nPrecios = max($nPrecios, count($precios));
                $filas[] = array_merge([$barcode, $codigo, $desc], array_map(fn ($p) => (string) round((float) $p, 2), $precios));
            }
        }

        $indice = min($indice, max(0, $nPrecios - 1));
        for ($i = count($nombres); $i < $nPrecios; $i++) $nombres[] = 'Precio ' . ($i + 1);
        $encabezado = array_merge(['Cód. barras', 'Código', 'Descripción'], $nombres);

        return [
            'filas'           => $filas ? array_merge([$encabezado], $filas) : [],
            'columnas'        => ['fila_inicio' => 2, 'col_barcode' => 0, 'col_codigo' => 1, 'col_nombre' => 2, 'col_precio' => 3 + $indice, 'col_cantidad' => null],
            'precios_con_iva' => $conIva,
            'columna_costo'   => $nombres[$indice] ?? null,
        ];
    }

    private static function esquema(): array
    {
        $texto = ['type' => ['string', 'null']];
        return [
            'type' => 'object',
            'properties' => [
                'columnas_precio' => ['type' => 'array', 'items' => ['type' => 'string']],
                'indice_costo'    => ['type' => 'integer'],
                'precios_con_iva' => ['type' => 'boolean'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'b' => $texto + ['description' => 'código de barras'],
                            'c' => $texto + ['description' => 'código del proveedor'],
                            'd' => ['type' => 'string', 'description' => 'descripción'],
                            'p' => ['type' => 'array', 'items' => ['type' => 'number'], 'description' => 'precios'],
                        ],
                        'required' => ['b', 'c', 'd', 'p'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['columnas_precio', 'indice_costo', 'precios_con_iva', 'items'],
            'additionalProperties' => false,
        ];
    }
}
