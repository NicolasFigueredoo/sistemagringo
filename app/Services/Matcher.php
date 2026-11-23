<?php

namespace App\Services;

/**
 * Busca a qué producto de Odoo corresponde cada renglón de la lista del proveedor.
 *
 * Reglas (de más segura a menos):
 *   1. barcode   -> código de barras exacto                  (se aplica solo)
 *   2. mapeo     -> ya se asoció a mano antes                (se aplica solo)
 *   3. codigo    -> código del proveedor cargado en Odoo, o  (se aplica solo)
 *                   referencia interna (default_code) única, o
 *                   código escrito entre paréntesis en el nombre: "TORNILLO PERFORANTE (P-324-234)"
 *                   (código suelto en el nombre, "ALICATE BAHCO 2101g-180d": como nombre, hay que tildarlo)
 *   4. nombre    -> nombre idéntico normalizado, único       (hay que tildarlo)
 *                   o código suelto que coincide (descripción, otras columnas, código corto)
 *   5. parecido  -> hasta 3 candidatos para elegir           (hay que elegir)
 *   6. ninguno
 *   (ignorado -> la última vez se eligió "no hacer nada" para ese renglón)
 *
 * Nunca asocia automáticamente por nombre parecido: "ESCOBA" jamás va solo a "PALO DE ESCOBA".
 * Sin dependencias de Laravel: recibe el catálogo como array.
 */
class Matcher
{
    /** odoo_product_id de un mapeo que significa "este renglón no se usa" */
    public const IGNORAR = 0;

    private const STOP =['de', 'del', 'la', 'el', 'los', 'las', 'x', 'c', 'p', 'con', 'para', 'y', 'en', 'un', 'una', 'mm', 'cm', 'mts', 'mt', 'm', 'u', 'un', 'unid', 'uds'];

    /** @var array<string,int> barcode => id */
    private array $porBarcode = [];
    /** @var array<string,int[]> codigoClave(default_code o barcode corto) => ids */
    private array $porCodigo = [];
    /** @var array<string,int[]> codigoClave(código del proveedor en Odoo) => ids */
    private array $porCodigoProv = [];
    /** @var array<string,int[]> codigoClave(código de barras corto de Odoo, "1253") => ids */
    private array $porBarcodeCorto = [];
    /** @var array<string,int[]> codigoClave(código entre paréntesis en el nombre de Odoo) => ids */
    private array $porCodigoNombre = [];
    /** @var array<string,int[]> codigoClave(palabra con forma de código en el nombre de Odoo) => ids */
    private array $porCodigoSuelto = [];
    /** @var array<string,int[]> código de Odoo sin la letra de variante del final ("MTG519AR" -> "MTG519") => ids */
    private array $porCodigoBase = [];
    /** @var array<string,int[]> nombre_norm => ids */
    private array $porNombre = [];
    /** @var array<string,int[]> token => ids (índice invertido) */
    private array $porToken = [];
    /** @var array<int,array> id => producto */
    private array $productos = [];

    /**
     * @param iterable<array{id:int,nombre:string,barcode:?string,default_code:?string}> $catalogo
     * @param array<string,int> $mapeos clave => odoo_product_id (del proveedor)
     * @param iterable<array{codigo:string,product_id:int}> $codigosProveedor códigos del proveedor en Odoo
     * @param bool $proveedorSeguro false = son códigos de cualquier proveedor (no se sabe cuál es en Odoo): hay que confirmar
     */
    public function __construct(iterable $catalogo, private array $mapeos = [], iterable $codigosProveedor = [], private bool $proveedorSeguro = true)
    {
        foreach ($catalogo as $p) {
            $p = (array) $p;
            $id = (int) $p['id'];
            $norm = self::normalizar($p['nombre']);
            $tokens = self::tokens($norm);
            $this->productos[$id] = ['id' => $id, 'nombre' => $p['nombre'], 'norm' => $norm, 'tokens' => $tokens];

            if ($b = self::limpiarCodigo($p['barcode'] ?? null)) {
                $this->porBarcode[$b] = $id;
                // códigos de barra cortos ("12445") son en realidad códigos internos
                if (strlen($b) < 8 && ($k = self::codigoClave($b))) $this->porBarcodeCorto[$k][] = $id;
            }
            if ($k = self::codigoClave($p['default_code'] ?? null)) $this->porCodigo[$k][] = $id;
            // referencia con texto de más ("7800dm-15 ex s 15"): la primera palabra también es el código
            $primera = preg_split('/\s+/', trim((string) ($p['default_code'] ?? '')))[0] ?? '';
            if ($primera !== trim((string) ($p['default_code'] ?? '')) && preg_match('/\d/', $primera) && ($k1 = self::codigoClave($primera)) && strlen($k1) >= 4) {
                $this->porCodigo[$k1][] = $id;
            }
            foreach (self::codigosEnNombre($p['nombre']) as $k) $this->porCodigoNombre[$k][] = $id;
            foreach (self::codigosSueltos($p['nombre']) as $k) $this->porCodigoSuelto[$k][] = $id;
            $this->porNombre[$norm][] = $id;
            foreach ($tokens as $t) $this->porToken[$t][] = $id;
        }
        foreach ([$this->porCodigo, $this->porCodigoNombre, $this->porCodigoSuelto] as $indice) {
            foreach ($indice as $k => $ids) {
                if ($b = self::base((string) $k)) foreach ($ids as $id) $this->porCodigoBase[$b][] = $id;
            }
        }
        foreach ($codigosProveedor as $c) {
            $c = (array) $c;
            if (isset($this->productos[(int) $c['product_id']]) && ($k = self::codigoClave($c['codigo']))) {
                $this->porCodigoProv[$k][] = (int) $c['product_id'];
            }
        }
    }

    /**
     * @param string[] $otrosCodigos posibles códigos sacados de otras columnas o del principio de la descripción
     * @return array{match:string, odoo_product_id:?int, odoo_nombre:?string, candidatos:array}
     */
    public function buscar(?string $barcode, ?string $codigo, ?string $nombre, array $otrosCodigos = []): array
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

        // 2. asociación guardada (0 = la última vez se marcó "no hacer nada")
        foreach (self::claves($barcode, $codigo, $nombre) as $clave) {
            if (($this->mapeos[$clave] ?? null) === self::IGNORAR) {
                return ['match' => 'ignorado', 'odoo_product_id' => null, 'odoo_nombre' => null, 'candidatos' => []];
            }
            if (isset($this->mapeos[$clave]) && isset($this->productos[$this->mapeos[$clave]])) {
                return $this->resultado('mapeo', $this->mapeos[$clave]);
            }
        }

        // 3. código del proveedor / referencia interna de Odoo (sin importar guiones, espacios ni ceros adelante)
        $principales = array_values(array_unique(array_filter(array_map(self::codigoClave(...), [$codigo, $barcode]))));
        $otros = array_values(array_diff(array_unique(array_filter(array_map(self::codigoClave(...), $otrosCodigos))), $principales));

        foreach ($principales as $k) {
            if ($id = self::unico($this->porCodigoProv[$k] ?? [])) return $this->resultado($this->proveedorSeguro ? 'codigo' : 'nombre', $id);
        }
        foreach ($principales as $k) {
            // un código corto ("100") puede ser casualidad: se confirma a mano
            if ($id = self::unico($this->porCodigo[$k] ?? [])) return $this->resultado(self::codigoFuerte($k) ? 'codigo' : 'nombre', $id);
        }
        foreach ($principales as $k) {
            // código de barras corto inventado en Odoo ("1253"): otro proveedor puede usar el mismo número
            // para otra cosa, así que el nombre tiene que parecerse; si no comparte ninguna palabra no se propone
            if (!($id = self::unico($this->porBarcodeCorto[$k] ?? []))) continue;
            $p = $this->productos[$id];
            $score = $norm === '' ? 0.0 : self::similitud(self::tokens($norm), $p['tokens'], $norm, $p['norm']);
            if ($score >= 0.45) return $this->resultado('codigo', $id);
            if ($score > 0) return $this->resultado('nombre', $id);
        }
        // "ST00118-POXIPOL": el proveedor le agrega la marca al código; probar cada parte con letras y números
        foreach ([$codigo, $barcode] as $c) {
            foreach (self::partesCodigo($c) as $k) {
                $id = self::unico($this->porCodigoProv[$k] ?? []) ?? self::unico($this->porCodigo[$k] ?? []);
                if ($id) return $this->resultado('codigo', $id);
            }
        }
        // código escrito en el nombre, entre paréntesis (Bahco): en Odoo, en la lista o en los dos
        $enNombre = self::codigosEnNombre($nombre);
        foreach ($principales as $k) {
            if (self::codigoFuerte($k) && ($id = self::unico($this->porCodigoNombre[$k] ?? []))) return $this->resultado('codigo', $id);
        }
        foreach ($enNombre as $k) {
            $id = self::unico($this->porCodigoProv[$k] ?? []) ?? self::unico($this->porCodigo[$k] ?? []) ?? self::unico($this->porCodigoNombre[$k] ?? []);
            if ($id) return $this->resultado('codigo', $id);
        }

        foreach ($otros as $k) {
            if (!self::codigoFuerte($k)) continue;
            $id = self::unico($this->porCodigoProv[$k] ?? []) ?? self::unico($this->porCodigo[$k] ?? []);
            if ($id) return $this->resultado('nombre', $id);
        }
        // código suelto dentro del nombre de Odoo ("ALICATE BAHCO 2101g-180d"): puede ser casualidad, se confirma a mano
        // si el código está en 2 a 5 productos (el blister "P-3140-100" y el suelto "3140-100"), se ofrecen para elegir
        // un número solo ("8172") en un producto sin ninguna palabra en común puede ser casualidad: va al final de las opciones
        [$porCodigoVarios, $dudosos] = [[], []];
        $palabras = self::tokens($norm);
        foreach (array_unique(array_merge($principales, $enNombre)) as $k) {
            if (strlen($k) < 4) continue;
            $ids = array_values(array_unique($this->porCodigoSuelto[$k] ?? []));
            if (count($ids) > 5) continue;
            foreach ($ids as $id) {
                $seguro = !ctype_digit($k) || !$palabras || array_intersect($palabras, $this->productos[$id]['tokens']);
                if ($seguro && count($ids) === 1) return $this->resultado('nombre', $id);
                ${$seguro ? 'porCodigoVarios' : 'dudosos'}[$id] = ['id' => $id, 'nombre' => $this->productos[$id]['nombre'], 'score' => 1.0];
            }
        }
        if ($porCodigoVarios || $dudosos) {
            foreach ($norm === '' ? [] : $this->parecidos($norm, 3) as $c) $porCodigoVarios[$c['id']] ??= $c;
            $porCodigoVarios += $dudosos;
            return ['match' => 'parecido', 'odoo_product_id' => null, 'odoo_nombre' => null, 'candidatos' => array_slice(array_values($porCodigoVarios), 0, 5)];
        }

        if ($norm === '') {
            return ['match' => 'ninguno', 'odoo_product_id' => null, 'odoo_nombre' => null, 'candidatos' => []];
        }

        // 4. nombre idéntico y único
        $ids = $this->porNombre[$norm] ?? [];
        if (count($ids) === 1) {
            return $this->resultado('nombre', $ids[0]);
        }

        // 5. parecidos
        $candidatos = $this->parecidos($norm, 3);
        if ($candidatos) {
            return ['match' => 'parecido', 'odoo_product_id' => null, 'odoo_nombre' => null, 'candidatos' => $candidatos];
        }

        return ['match' => 'ninguno', 'odoo_product_id' => null, 'odoo_nombre' => null, 'candidatos' => []];
    }

    /**
     * Renglón de una lista Sysvent (Angel M. Beber): el código de la 1ª columna es INTERNO del proveedor
     * (solo sirve para recordar la asociación); el código del producto viene escrito en la descripción.
     *
     *   1. asociación guardada por código interno            -> mapeo   (seguro)
     *   2. código de la descripción = referencia interna,
     *      código del proveedor en Odoo o "(código)" en el nombre, único -> codigo (seguro)
     *   3. código de la descripción suelto en el nombre de Odoo: seguro si comparte alguna palabra
     *      o la marca del rubro; si no, hay que tildarlo
     *   4. varios productos con el mismo código -> se ofrecen para elegir, primero los de la marca del rubro
     *   5. el mismo código con otra letra de variante al final ("MTG-5-19" / "MTG-5-19AR") -> tildar o elegir
     *   6. "PU 1/2", "SX 23" (serie + medida): producto de la marca que tiene todas esas palabras -> tildar
     *   7. por nombre, como cualquier lista
     *
     * @param string|null $rubro "111-BAHCO", "026-DEWALT MAQUINAS": acota y ordena cuando hay varios
     */
    public function buscarSysvent(string $codigoInterno, ?string $descripcion, ?string $columnaCodigo = null, ?string $rubro = null, ?string $marcaLista = null): array
    {
        $nombre = CodigosDescripcion::limpiar((string) $descripcion);
        $norm = self::normalizar($nombre);
        $palabras = self::tokens($norm);
        $marca = self::tokensMarca($rubro, $marcaLista);

        // 1. asociación guardada: por código interno de Beber (no cambia aunque cambie la descripción)
        foreach (self::claves(null, $codigoInterno, null) as $clave) {
            if (($this->mapeos[$clave] ?? null) === self::IGNORAR) {
                return ['match' => 'ignorado', 'odoo_product_id' => null, 'odoo_nombre' => null, 'candidatos' => []];
            }
            if (isset($this->mapeos[$clave]) && isset($this->productos[$this->mapeos[$clave]])) {
                return $this->resultado('mapeo', $this->mapeos[$clave]);
            }
        }

        $candidatos = CodigosDescripcion::candidatos($descripcion, $columnaCodigo);
        // los códigos largos primero: "K8901Z1.3/8" decide antes que "K8901Z" (que está en todas las medidas)
        usort($candidatos, fn ($a, $b) => [$b['fuerte'], strlen($b['clave'])] <=> [$a['fuerte'], strlen($a['clave'])]);

        $varios = [];
        $sueltoUnico = null;
        foreach ($candidatos as $c) {
            $k = $c['clave'];
            // 2. referencia interna, código del proveedor en Odoo o "(código)" en el nombre
            $ref = array_values(array_unique(array_merge($this->porCodigoProv[$k] ?? [], $this->porCodigo[$k] ?? [], $this->porCodigoNombre[$k] ?? [])));
            if (count($ref) === 1 && !isset($varios[$ref[0]])) {
                $comparte = $this->comparte($ref[0], $palabras, $marca);
                // seguro solo si además comparte una palabra o la marca ("10-30-51 ARCO" no es "bocallave 5mm")
                if ($comparte) return $this->resultado($c['fuerte'] ? 'codigo' : 'nombre', $ref[0]);
                $varios[$ref[0]] ??= true;
                continue;
            }
            // 3. código escrito suelto en el nombre de Odoo ("pinza BAHCO 2678G-160 universal")
            $suelto = array_values(array_unique($this->porCodigoSuelto[$k] ?? []));
            if (count($suelto) === 1 && $sueltoUnico === null && $this->comparte($suelto[0], $palabras, $marca)) {
                $sueltoUnico = [$suelto[0], $c];
            }
            // sin palabras en común solo se ofrece si el código es largo: un número corto en otro producto es casualidad
            if (!$c['fuerte'] && strlen($k) < 5) continue;
            foreach (array_merge($ref, count($suelto) <= 8 ? $suelto : []) as $id) $varios[$id] ??= true;
        }
        if ($sueltoUnico) {
            [$id, $c] = $sueltoUnico;
            // seguro si además comparte una palabra o la marca; si solo coincide el número, se confirma
            return $this->resultado($c['fuerte'] ? 'codigo' : 'nombre', $id);
        }

        // 4. el mismo código en varios productos: primero los de la marca del rubro, después los de nombre parecido
        // (solo los que comparten alguna palabra o la marca: el resto es un número repetido por casualidad)
        $varios = array_filter(array_keys($varios), fn ($id) => $this->comparte($id, $palabras, $marca));
        if ($varios) {
            return ['match' => 'parecido', 'odoo_product_id' => null, 'odoo_nombre' => null,
                'candidatos' => $this->ordenarCandidatos($varios, $palabras, $norm, $marca)];
        }

        // 5. el mismo código con otra letra de variante al final: "MTG-5-19" = "MTG-5-19AR", "BKE850901N" = "bke850901".
        //    Puede ser otra versión del producto ("8072A" fosfatizada, "8072C" cromada): siempre se confirma a mano
        $parecidos = [];
        foreach ($candidatos as $c) {
            $k = $c['clave'];
            $b = self::base($k);
            $ids = array_merge($this->porCodigoBase[$k] ?? [], $b ? ($this->porCodigo[$b] ?? []) : [], $b ? ($this->porCodigoSuelto[$b] ?? []) : [], $b ? ($this->porCodigoNombre[$b] ?? []) : []);
            // tiene que compartir una palabra de verdad: en una lista de una sola marca, la marca no dice nada
            foreach (array_unique($ids) as $id) if ($this->comparte($id, $palabras, [])) $parecidos[$id] = true;
        }
        if (count($parecidos) === 1) return $this->resultado('nombre', array_key_first($parecidos));
        if ($parecidos && count($parecidos) <= 5) {
            return ['match' => 'parecido', 'odoo_product_id' => null, 'odoo_nombre' => null,
                'candidatos' => $this->ordenarCandidatos(array_keys($parecidos), $palabras, $norm, $marca)];
        }

        // 6. serie + medida ("PU 1/2" = "llave 1/2 combinada bahco pu"): todas las palabras están en el producto
        if ($palabras && count($palabras) <= 4 && $marca && preg_grep('/\d/', $palabras)) {
            $ids = $this->contienenTodas($palabras, $marca);
            if (count($ids) === 1) return $this->resultado('nombre', $ids[0]);
            if ($ids && count($ids) <= 5) {
                return ['match' => 'parecido', 'odoo_product_id' => null, 'odoo_nombre' => null,
                    'candidatos' => $this->ordenarCandidatos($ids, $palabras, $norm, $marca)];
            }
        }

        // 7. como cualquier renglón, solo por nombre (el código interno no se compara con Odoo)
        if ($norm === '') return ['match' => 'ninguno', 'odoo_product_id' => null, 'odoo_nombre' => null, 'candidatos' => []];
        $r = $this->buscar(null, null, $nombre);
        // sin código en la descripción nada es seguro: "(20 UNIDADES)" en dos nombres no es el mismo producto
        if ($r['match'] === 'codigo') $r['match'] = 'nombre';
        if ($r['match'] === 'parecido' && $marca) {
            $r['candidatos'] = $this->ordenarCandidatos(array_column($r['candidatos'], 'id'), $palabras, $norm, $marca);
        }
        return $r;
    }

    /** Código sin las 1 a 3 letras de variante del final ("MTG519AR" -> "MTG519", "1446ARG" -> "1446"); null si no tiene. */
    private static function base(string $k): ?string
    {
        return preg_match('/^(.*\d)[A-Z]{1,3}$/', $k, $m) && strlen($m[1]) >= 4 ? $m[1] : null;
    }

    /** Palabras de la marca: "111-BAHCO" -> [bahco]; "026-DEWALT MAQUINAS" -> [dewalt, maquinas]. */
    public static function tokensMarca(?string $rubro, ?string $marcaLista = null): array
    {
        $r = preg_replace('/^\s*\d+\s*-\s*/', '', (string) $rubro) . ' ' . $marcaLista;
        return array_values(array_filter(self::tokens(self::normalizar($r)), fn ($t) => strlen($t) >= 3 && !preg_match('/\d/', $t)));
    }

    /** El producto comparte alguna palabra (no número) con la descripción, o es de la marca del rubro. */
    private function comparte(int $id, array $palabras, array $marca): bool
    {
        $t = $this->productos[$id]['tokens'] ?? [];
        $palabras = array_filter($palabras, fn ($p) => !preg_match('/\d/', $p) && strlen($p) >= 3);
        foreach (array_merge($palabras, $marca) as $p) {
            foreach ($t as $u) {
                if ($u === $p || (strlen($u) >= 4 && strlen($p) >= 4 && (str_starts_with($u, $p) || str_starts_with($p, $u)))) return true;
            }
        }
        return false;
    }

    /** Productos de la marca que tienen todas las palabras de la descripción. @return int[] */
    private function contienenTodas(array $palabras, array $marca): array
    {
        $ids = null;
        foreach ($palabras as $p) {
            $con = array_unique($this->porToken[$p] ?? []);
            $ids = $ids === null ? $con : array_intersect($ids, $con);
            if (!$ids) return [];
        }
        return array_values(array_filter($ids, function ($id) use ($marca, $palabras) {
            $t = $this->productos[$id]['tokens'];
            // la misma medida y nada más: "PU 1" no es "PU 1.1/16" ni "juego 1/4 a 1"
            $numP = preg_grep('/\d/', $palabras);
            $numT = preg_grep('/\d/', $t);
            return array_intersect($marca, $t) && !array_diff($numT, $numP);
        }));
    }

    /** @return array<int,array{id:int,nombre:string,score:float}> hasta 5, los de la marca primero */
    private function ordenarCandidatos(array $ids, array $palabras, string $norm, array $marca): array
    {
        $res = [];
        foreach (array_unique($ids) as $id) {
            if (!isset($this->productos[$id])) continue;
            $p = $this->productos[$id];
            $score = $palabras ? self::similitud($palabras, $p['tokens'], $norm, $p['norm']) : 0.0;
            $deMarca = $marca && array_intersect($marca, $p['tokens']) ? 1 : 0;
            $res[] = ['id' => $id, 'nombre' => $p['nombre'], 'score' => round($score, 2), 'marca' => $deMarca];
        }
        usort($res, fn ($a, $b) => [$b['marca'], $b['score']] <=> [$a['marca'], $a['score']]);
        return array_map(fn ($r) => array_diff_key($r, ['marca' => 1]), array_slice($res, 0, 5));
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

    /**
     * Código comparable: solo letras y números en mayúscula, sin ceros adelante si es todo número.
     * "LS-9" = "ls 9" = "LS9" ; "022014" = "22014" ; "ABC.990" = "abc-990"
     */
    public static function codigoClave(?string $c): ?string
    {
        if ($c === null) return null;
        $c = strtoupper(trim($c));
        if (preg_match('/^\d+\.0+$/', $c)) $c = preg_replace('/\.0+$/', '', $c);   // Excel: "12345.0"
        $c = preg_replace('/[^A-Z0-9]/', '', $c);
        if (ctype_digit($c)) $c = ltrim($c, '0');
        return $c === '' ? null : $c;
    }

    /**
     * Códigos escritos entre paréntesis o corchetes en un nombre: "TORNILLO PERFORANTE (P-324-234)" -> ["P324234"].
     * Solo lo que parece un código (con números, sin espacios de más); "(x10)", "(1/2)" o "(10 UNID)" no cuentan.
     * @return string[] claves (codigoClave)
     */
    public static function codigosEnNombre(?string $nombre): array
    {
        if ($nombre === null || !preg_match_all('/[(\[]\s*([^()\[\]]{3,30}?)\s*[)\]]/u', $nombre, $m)) return [];
        $out = [];
        foreach ($m[1] as $c) {
            $c = preg_replace('/\s*-\s*/', '-', $c);   // "( 2678- g - 160 )" -> "2678-g-160"
            if (!preg_match('/\d/', $c) || preg_match('/\s\S*\s/', $c) || self::esMedida($c)) continue;   // a lo sumo un espacio
            $k = self::codigoClave($c);
            if ($k && strlen($k) >= 4) $out[] = $k;
        }
        return array_values(array_unique($out));
    }

    /**
     * Palabras del nombre con forma de código: "ALICATE BAHCO plano 2101g-180d" -> ["2101G180D"].
     * Con "P-" adelante (blister Bahco) también sin la P: "P-3140-100" -> ["P3140100", "3140100"].
     * @return string[] claves (codigoClave)
     */
    public static function codigosSueltos(?string $nombre): array
    {
        $out = [];
        foreach (preg_split('/[\s(),;*\[\]]+/u', (string) $nombre) as $w) {
            $w = trim($w, '.-/');
            if (!preg_match('/\d/', $w) || self::esMedida($w)) continue;
            $k = self::codigoClave($w);
            if (!$k || strlen($k) < 4) continue;
            $out[] = $k;
            if (preg_match('/^P(\d.{3,})$/', $k, $m)) $out[] = $m[1];
        }
        return array_values(array_unique($out));
    }

    /** "1/2", "3/4 H", "12mm", "x10", "450gs", "10 UNID", "1000v", "60,7cm": medidas y cantidades, no códigos. */
    private static function esMedida(string $c): bool
    {
        $c = trim($c);
        // un número solo ("8172") sí puede ser código; con unidad o "x" adelante es medida o cantidad
        return (bool) preg_match('~^x\s*[\d.,]+$~i', $c)
            || preg_match('~^[\d.,]+\s*("|\'\'|\'|´|#|mm|cm|mts?|m|kg|grs?|gs|g|lts?|l|u|un|unid|unidades|uds|pz|pzas?|piezas|v|vol|w|tn|h|dientes)\.?$~i', $c)
            || preg_match('~^[\d.,]+$~', $c) && preg_match('~[.,]~', $c)
            || preg_match('~^\d+(\s*\d+)?/\d+~', $c);
    }

    /** Partes de un código compuesto que por sí solas parecen un código: "ST00118-POXIPOL" -> ["ST00118"] */
    private static function partesCodigo(?string $c): array
    {
        $partes = preg_split('/[\s\-\/_]+/', trim((string) $c));
        if (count($partes) < 2) return [];
        $out = [];
        foreach ($partes as $p) {
            $k = self::codigoClave($p);
            if ($k && strlen($k) >= 4 && preg_match('/[A-Z]/', $k) && preg_match('/\d/', $k)) $out[] = $k;
        }
        return $out;
    }

    /** Código con suficiente información para no coincidir por casualidad: tiene letras o 4+ dígitos. */
    private static function codigoFuerte(string $k): bool
    {
        return preg_match('/[A-Z]/', $k) || strlen($k) >= 4;
    }

    /** El id si hay uno solo (el mismo código en dos productos no sirve para decidir). */
    private static function unico(array $ids): ?int
    {
        $ids = array_unique($ids);
        return count($ids) === 1 ? (int) reset($ids) : null;
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
