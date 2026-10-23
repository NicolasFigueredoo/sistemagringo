<?php

namespace App\Services;

/**
 * Busca a qué producto de Odoo corresponde cada renglón de la lista del proveedor.
 *
 * Reglas (de más segura a menos):
 *   1. barcode   -> código de barras exacto                  (se aplica solo)
 *   2. mapeo     -> ya se asoció a mano antes                (se aplica solo)
 *   3. nombre    -> nombre idéntico normalizado, único       (hay que tildarlo)
 *   4. parecido  -> hasta 3 candidatos para elegir           (hay que elegir)
 *   5. ninguno
 *
 * Nunca asocia automáticamente por nombre parecido: "ESCOBA" jamás va solo a "PALO DE ESCOBA".
 * Sin dependencias de Laravel: recibe el catálogo como array.
 */
class Matcher
{
    private const STOP = ['de', 'del', 'la', 'el', 'los', 'las', 'x', 'c', 'p', 'con', 'para', 'y', 'en', 'un', 'una', 'mm', 'cm', 'mts', 'mt', 'm', 'u', 'un', 'unid', 'uds'];

    /** @var array<string,int> barcode => id */
    private array $porBarcode = [];
    /** @var array<string,int> default_code => id */
    private array $porCodigo = [];
    /** @var array<string,int[]> nombre_norm => ids */
    private array $porNombre = [];
    /** @var array<string,int[]> token => ids (índice invertido) */
    private array $porToken = [];
    /** @var array<int,array> id => producto */
    private array $productos = [];

    /**
     * @param iterable<array{id:int,nombre:string,barcode:?string,default_code:?string}> $catalogo
     * @param array<string,int> $mapeos clave => odoo_product_id (del proveedor)
     */
    public function __construct(iterable $catalogo, private array $mapeos = [])
    {
        foreach ($catalogo as $p) {
            $p = (array) $p;
            $id = (int) $p['id'];
            $norm = self::normalizar($p['nombre']);
            $tokens = self::tokens($norm);
            $this->productos[$id] = ['id' => $id, 'nombre' => $p['nombre'], 'norm' => $norm, 'tokens' => $tokens];

            if ($b = self::limpiarCodigo($p['barcode'] ?? null)) $this->porBarcode[$b] = $id;
            if ($c = self::limpiarCodigo($p['default_code'] ?? null)) $this->porCodigo[$c] = $id;
            $this->porNombre[$norm][] = $id;
            foreach ($tokens as $t) $this->porToken[$t][] = $id;
        }
    }

    /**
     * @return array{match:string, odoo_product_id:?int, odoo_nombre:?string, candidatos:array}
     */
    public function buscar(?string $barcode, ?string $codigo, ?string $nombre): array
    {
        $barcode = self::limpiarCodigo($barcode);
        $codigo = self::limpiarCodigo($codigo);
        $norm = self::normalizar((string) $nombre);

        // 1. código de barras (también probamos el código del proveedor por si es un EAN)
        foreach ([$barcode, $codigo] as $b) {
            if ($b && strlen($b) >= 8 && isset($this->porBarcode[$b])) {
                return $this->resultado('barcode', $this->porBarcode[$b]);
            }
        }

        // 2. asociación guardada
        foreach (self::claves($barcode, $codigo, $nombre) as $clave) {
            if (isset($this->mapeos[$clave]) && isset($this->productos[$this->mapeos[$clave]])) {
                return $this->resultado('mapeo', $this->mapeos[$clave]);
            }
        }

        // 2b. código interno idéntico (default_code) -> lo tratamos como "nombre": hay que confirmarlo
        if ($codigo && isset($this->porCodigo[$codigo])) {
            return $this->resultado('nombre', $this->porCodigo[$codigo]);
        }

        if ($norm === '') {
            return ['match' => 'ninguno', 'odoo_product_id' => null, 'odoo_nombre' => null, 'candidatos' => []];
        }

        // 3. nombre idéntico y único
        $ids = $this->porNombre[$norm] ?? [];
        if (count($ids) === 1) {
            return $this->resultado('nombre', $ids[0]);
        }

        // 4. parecidos
        $candidatos = $this->parecidos($norm, 3);
        if ($candidatos) {
            return ['match' => 'parecido', 'odoo_product_id' => null, 'odoo_nombre' => null, 'candidatos' => $candidatos];
        }

        return ['match' => 'ninguno', 'odoo_product_id' => null, 'odoo_nombre' => null, 'candidatos' => []];
    }

    /** @return array<int,array{id:int,nombre:string,score:float}> */
    public function parecidos(string $norm, int $max = 3, float $minimo = 0.45): array
    {
        $tokens = self::tokens($norm);
        if (!$tokens) return [];

        // candidatos: productos que comparten al menos una palabra
        $cuenta = [];
        foreach ($tokens as $t) {
            foreach ($this->porToken[$t] ?? [] as $id) {
                $cuenta[$id] = ($cuenta[$id] ?? 0) + 1;
            }
        }
        if (!$cuenta) return [];

        // si hay muchísimos, quedarse con los que comparten más palabras
        arsort($cuenta);
        $cuenta = array_slice($cuenta, 0, 200, true);

        $res = [];
        foreach (array_keys($cuenta) as $id) {
            $p = $this->productos[$id];
            $score = self::similitud($tokens, $p['tokens'], $norm, $p['norm']);
            if ($score >= $minimo) {
                $res[] = ['id' => $id, 'nombre' => $p['nombre'], 'score' => round($score, 2)];
            }
        }
        usort($res, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($res, 0, $max);
    }

    /** Claves con las que se guarda / busca una asociación manual. */
    public static function claves(?string $barcode, ?string $codigo, ?string $nombre): array
    {
        $c = [];
        if ($b = self::limpiarCodigo($barcode)) $c[] = 'b:' . $b;
        if ($k = self::limpiarCodigo($codigo)) $c[] = 'c:' . $k;
        $n = self::normalizar((string) $nombre);
        if ($n !== '') $c[] = 'n:' . $n;
        return $c;
    }

    public static function normalizar(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
        // "1/2" "3,5" "18mm" se conservan como token; el resto de símbolos son espacio
        $s = preg_replace('/(\d),(\d)/', '$1.$2', $s);
        // "18mm" -> "18 mm", "rk3000" -> "rk 3000": así la medida es su propia palabra
        $s = preg_replace('/(\d)([a-z])/', '$1 $2', $s);
        $s = preg_replace('/([a-z])(\d)/', '$1 $2', $s);
        $s = preg_replace('/[^a-z0-9.\/]+/', ' ', $s);
        $s = preg_replace('/(?<!\d)\.|\.(?!\d)/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** @return string[] palabras significativas, sin repetir */
    public static function tokens(string $norm): array
    {
        $out = [];
        foreach (explode(' ', $norm) as $t) {
            if ($t === '' || in_array($t, self::STOP, true)) continue;
            if (strlen($t) < 2 && !ctype_digit($t)) continue;
            $out[$t] = true;
        }
        return array_map('strval', array_keys($out));   // array_keys convierte "18" en int
    }

    private static function limpiarCodigo(?string $c): ?string
    {
        if ($c === null) return null;
        $c = strtoupper(preg_replace('/\s+/', '', trim($c)));
        // Excel a veces convierte EANs en "7790001234567.0" o "7.79E+12"
        if (preg_match('/^\d+\.0+$/', $c)) $c = preg_replace('/\.0+$/', '', $c);
        return $c === '' ? null : $c;
    }

    /**
     * Similitud estricta: exige que las palabras de AMBOS lados estén presentes.
     * "escoba" vs "palo de escoba" -> comparten 1 de 2 palabras -> 0.5 (solo sugerencia).
     */
    private static function similitud(array $a, array $b, string $na, string $nb): float
    {
        // los números (medidas, modelos, cantidades) tienen que ser LOS MISMOS: 402 != 403, 18mm != 35mm
        $numA = array_values(array_filter($a, fn ($t) => preg_match('/\d/', $t)));
        $numB = array_values(array_filter($b, fn ($t) => preg_match('/\d/', $t)));
        if ($numA && $numB && array_merge(array_diff($numA, $numB), array_diff($numB, $numA))) {
            return 0.0;   // otra medida/modelo: no se sugiere
        }

        // palabras en común, aceptando abreviaturas y plurales: "abraz" = "abrazadera" = "abrazaderas"
        $sobraA = [];
        $comunes = 0;
        $usadasB = [];
        foreach ($a as $t) {
            $hit = null;
            foreach ($b as $j => $u) {
                if (isset($usadasB[$j])) continue;
                if ($t === $u || (strlen($t) >= 3 && strlen($u) >= 3 && !ctype_digit($t) && (str_starts_with($u, $t) || str_starts_with($t, $u)))) {
                    $hit = $j; break;
                }
            }
            if ($hit === null) { $sobraA[] = $t; } else { $usadasB[$hit] = true; $comunes++; }
        }
        $sobraB = count($b) - count($usadasB);
        if ($comunes === 0) return 0.0;

        $jaccard = $comunes / (count($a) + $sobraB);
        $cobertura = $comunes / count($a);
        similar_text($na, $nb, $pct);
        $score = 0.5 * $jaccard + 0.3 * $cobertura + 0.2 * ($pct / 100);

        // a cada lado le sobra una palabra distinta (AZUL vs NEGRA, CUERO vs GOMA): es otra variante
        if ($sobraA && $sobraB) {
            $score *= 0.55;
        }
        return $score;
    }

    private function resultado(string $tipo, int $id): array
    {
        return [
            'match' => $tipo,
            'odoo_product_id' => $id,
            'odoo_nombre' => $this->productos[$id]['nombre'] ?? null,
            'candidatos' => [],
        ];
    }
}
