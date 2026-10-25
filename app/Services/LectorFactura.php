<?php

namespace App\Services;

use Anthropic\Client;
use RuntimeException;

/**
 * Lee los renglones de una factura de proveedor (fotos o PDF) con la API de Claude.
 * Devuelve los datos ya ordenados; la búsqueda en Odoo la hace el Matcher como con las listas.
 */
class LectorFactura
{
    private const TIPOS_IMAGEN = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const LADO_MAX = 2400;          // px: las fotos del celular se achican, la letra chica se sigue leyendo

    private const INSTRUCCIONES = <<<'TXT'
        Sos el asistente de una ferretería en Argentina. Te paso las fotos (o el PDF) de UNA factura o remito de un proveedor;
        si hay varias imágenes son páginas de la misma factura, no repitas renglones.

        Pasá cada renglón de mercadería a la lista "items":
        - codigo_barras: el código EAN/UPC (8 a 14 dígitos) si está impreso en el renglón; si no, null.
        - codigo: el código de artículo del proveedor (columna "Código", "Art.", "Cód.", etc.) tal cual está escrito; si no hay, null.
        - descripcion: la descripción del producto tal cual.
        - cantidad: unidades que entran. Si el renglón dice cajas/packs y aclara cuántas unidades trae cada uno, poné el total de unidades.
        - precio_unitario: precio de UNA unidad, ya restadas las bonificaciones o descuentos de ese renglón. Número sin símbolos.
          Si la factura solo trae el importe total del renglón, dividilo por la cantidad.

        No incluyas subtotales, IVA, percepciones, fletes, redondeos ni otros renglones que no sean productos.
        precios_con_iva: true si los precios de los renglones incluyen IVA (factura B o C, ticket, o la factura lo aclara); false si es factura A con IVA discriminado aparte.
        descuento_general: % de descuento o bonificación que se aplica a toda la factura (no a un renglón), o 0.
        Si un dato no se lee bien, no lo inventes: poné null (o salteá el renglón si no se lee el precio).
        TXT;

    public function __construct(private string $apiKey, private string $modelo) {}

    public static function desdeConfig(): self
    {
        $key = config('services.anthropic.key');
        if (!$key) throw new RuntimeException('Falta ANTHROPIC_API_KEY en el archivo .env para leer facturas.');
        return new self($key, config('services.anthropic.model'));
    }

    /**
     * @param string[] $rutas fotos (jpg, png, webp) o PDF de la factura
     * @return array{proveedor:?string, numero:?string, fecha:?string, precios_con_iva:bool, descuento_general:float, items:array}
     */
    public function leer(array $rutas): array
    {
        $contenido = array_map(fn ($r) => self::bloque($r), $rutas);
        $contenido[] = ['type' => 'text', 'text' => 'Pasame los renglones de esta factura.'];

        $client = new Client(apiKey: $this->apiKey);
        $resp = $client->beta->messages->create(
            model: $this->modelo,
            maxTokens: 16000,
            system: self::INSTRUCCIONES,
            messages: [['role' => 'user', 'content' => $contenido]],
            outputConfig: ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => self::esquema()]],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
            requestOptions: ['timeout' => 240],
        );

        if ($resp->stopReason === 'refusal') throw new RuntimeException('La IA no quiso leer esta imagen. Probá con otra foto.');
        if ($resp->stopReason === 'max_tokens') throw new RuntimeException('La factura es muy larga para leerla de una vez: subila en partes.');

        foreach ($resp->content as $b) {
            if ($b->type === 'text') {
                $datos = json_decode($b->text, true);
                if (is_array($datos)) return self::limpiar($datos);
            }
        }
        throw new RuntimeException('No se pudo leer la respuesta de la IA.');
    }

    private static function bloque(string $ruta): array
    {
        $tipo = mime_content_type($ruta) ?: '';
        if ($tipo === 'application/pdf') {
            return ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode(file_get_contents($ruta))]];
        }
        [$datos, $tipo] = self::imagen($ruta, $tipo);
        return ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $tipo, 'data' => base64_encode($datos)]];
    }

    /** Endereza y achica la foto (las del celular pesan varios MB y la API acepta hasta 5 MB). */
    private static function imagen(string $ruta, string $tipo): array
    {
        $img = function_exists('imagecreatefromstring') ? @imagecreatefromstring(file_get_contents($ruta)) : false;
        if (!$img) {
            if (!in_array($tipo, self::TIPOS_IMAGEN, true)) throw new RuntimeException('Formato de imagen no soportado: usá JPG, PNG o PDF.');
            return [file_get_contents($ruta), $tipo];
        }

        $giro = ['3' => 180, '6' => -90, '8' => 90][(string) (function_exists('exif_read_data') ? (@exif_read_data($ruta)['Orientation'] ?? '') : '')] ?? 0;
        if ($giro) $img = imagerotate($img, $giro, 0);

        [$w, $h] = [imagesx($img), imagesy($img)];
        $f = min(1, self::LADO_MAX / max($w, $h));
        if ($f < 1) $img = imagescale($img, (int) round($w * $f), (int) round($h * $f), IMG_BICUBIC);

        ob_start();
        imagejpeg($img, null, 85);
        return [ob_get_clean(), 'image/jpeg'];
    }

    private static function esquema(): array
    {
        $texto = ['type' => ['string', 'null']];
        $numero = ['type' => ['number', 'null']];
        return [
            'type' => 'object',
            'properties' => [
                'proveedor'         => $texto,
                'numero'            => $texto,
                'fecha'             => $texto,
                'precios_con_iva'   => ['type' => 'boolean'],
                'descuento_general' => ['type' => 'number'],
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'codigo_barras'   => $texto,
                            'codigo'          => $texto,
                            'descripcion'     => ['type' => 'string'],
                            'cantidad'        => $numero,
                            'precio_unitario' => ['type' => 'number'],
                        ],
                        'required' => ['codigo_barras', 'codigo', 'descripcion', 'cantidad', 'precio_unitario'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['proveedor', 'numero', 'fecha', 'precios_con_iva', 'descuento_general', 'items'],
            'additionalProperties' => false,
        ];
    }

    private static function limpiar(array $d): array
    {
        $txt = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
        $items = [];
        foreach ($d['items'] ?? [] as $it) {
            $barcode = preg_replace('/\D/', '', (string) ($it['codigo_barras'] ?? ''));
            $items[] = [
                'barcode'  => strlen($barcode) >= 8 ? $barcode : null,
                'codigo'   => $txt($it['codigo'] ?? null),
                'nombre'   => $txt($it['descripcion'] ?? null),
                'cantidad' => is_numeric($it['cantidad'] ?? null) ? (float) $it['cantidad'] : null,
                'precio'   => is_numeric($it['precio_unitario'] ?? null) ? (float) $it['precio_unitario'] : null,
            ];
        }
        return [
            'proveedor'         => $txt($d['proveedor'] ?? null),
            'numero'            => $txt($d['numero'] ?? null),
            'fecha'             => $txt($d['fecha'] ?? null),
            'precios_con_iva'   => (bool) ($d['precios_con_iva'] ?? false),
            'descuento_general' => max(0, (float) ($d['descuento_general'] ?? 0)),
            'items'             => $items,
        ];
    }
}
