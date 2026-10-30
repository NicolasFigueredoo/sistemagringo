<?php

namespace App\Services;

use Anthropic\Client;
use App\Models\LecturaFactura;
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

    private const INSTRUCCIONES = <<<'TXT'
        Sos el asistente de una ferretería en Argentina. Te paso el texto (extraído de un PDF, con la disposición original en columnas)
        de un tramo de la lista de precios de un proveedor. Pasá cada producto a la lista "items":
        - codigo_barras: el código EAN/UPC (8 a 14 dígitos) si figura; si no, null. Un código de 8+ dígitos en la columna "Código"/"Cód." es un EAN.
        - codigo: el código o SKU del proveedor si no es un EAN (ej. "TER320", "RH-2402", "PY 10 CTOPE", "8670C"); si no hay, null.
        - descripcion: la descripción completa del producto. Si está partida en varias líneas, unila. Podés agregar la marca al final si figura aparte.
        - precios: TODOS los importes de precio del renglón, en el orden de las columnas de precio de la lista, como números
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
    public function leer(string $texto): array
    {
        $respuestas = [];
        foreach (self::tramos($texto) as $tramo) {
            $respuestas[] = $this->pedir($tramo);
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
        $client = new Client(apiKey: $this->apiKey);
        $resp = $client->beta->messages->create(
            model: $this->modelo,
            maxTokens: 32000,
            system: self::INSTRUCCIONES,
            messages: [['role' => 'user', 'content' => [['type' => 'text', 'text' => "Lista de precios:\n\n" . $tramo]]]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::esquema()]],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
            requestOptions: ['timeout' => 300],
        );

        LecturaFactura::create([
            'user_id'        => auth()->id(),
            'tipo'           => 'lista',
            'fotos'          => 0,
            'modelo'         => $resp->model,
            'tokens_entrada' => $resp->usage->inputTokens,
            'tokens_salida'  => $resp->usage->outputTokens,
        ]);

        if ($resp->stopReason === 'refusal') throw new RuntimeException('La IA no quiso leer la lista.');
        if ($resp->stopReason === 'max_tokens') throw new RuntimeException('Un tramo de la lista es demasiado largo para la IA.');

        foreach ($resp->content as $b) {
            if ($b->type === 'text') {
                $datos = json_decode($b->text, true);
                if (is_array($datos)) return $datos;
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
                            'codigo_barras' => $texto,
                            'codigo'        => $texto,
                            'descripcion'   => ['type' => 'string'],
                            'precios'       => ['type' => 'array', 'items' => ['type' => 'number']],
                        ],
                        'required' => ['codigo_barras', 'codigo', 'descripcion', 'precios'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['columnas_precio', 'indice_costo', 'precios_con_iva', 'items'],
            'additionalProperties' => false,
        ];
    }
}
