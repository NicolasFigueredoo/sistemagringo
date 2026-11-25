<?php

namespace App\Services;

use App\Support\Instancia;
use RuntimeException;

/**
 * Deja el Odoo nuevo igual al viejo:
 *  1. categorías: crea las que faltan (con su categoría padre)
 *  2. productos: los empareja por código de barras, referencia o nombre exacto; crea los que faltan
 *     y a los que ya están les pisa nombre, códigos, costo, precio de venta y categoría con los del viejo
 *  3. stock: el disponible de cada producto queda igual al del viejo (ajuste de inventario)
 *  4. guarda los dos catálogos en las bases locales
 * Todo en lotes y en paralelo. Nunca escribe en el Odoo viejo.
 */
class SincronizarOdoos
{
    private const LOTE_CREAR = 100;
    private const LOTE_STOCK = 100;

    private array $resumen = [
        'categorias_creadas' => 0, 'productos_creados' => 0, 'productos_actualizados' => 0,
        'stock_ajustado' => 0, 'sin_cambios' => 0, 'errores' => 0, 'detalle_errores' => [],
    ];
    /** @var callable|null fn(string $paso, int $hechos, int $total) */
    private $avance;
    private array $tipoCamposViejo = [];
    private array $tipoCamposNuevo = [];
    private array $barcodesNuevo = [];

    public function __construct(private OdooClient $viejo, private OdooClient $nuevo, ?callable $avance = null)
    {
        $this->avance = $avance;
    }

    public static function crear(?callable $avance = null): self
    {
        // viejo = ferreteria-tio-gringo (ODOO_VIEJO_*), nuevo = ferreteriatiogringo (ODOO_*)
        foreach (['Odoo viejo (ODOO_VIEJO_*)' => Instancia::configSecundario(), 'Odoo nuevo (ODOO_*)' => Instancia::configPrincipal()] as $nombre => $c) {
            if (empty($c['user']) || empty($c['api_key'])) throw new RuntimeException("Falta el usuario o la API key del $nombre en el .env.");
        }
        return new self(OdooClient::desdeArray(Instancia::configSecundario()), OdooClient::desdeArray(Instancia::configPrincipal()), $avance);
    }

    public function ejecutar(): array
    {
        @set_time_limit(0);
        $inicio = microtime(true);

        $this->paso('Conectando con los dos Odoo');
        $this->viejo->uid();
        $this->nuevo->uid();
        $this->tipoCamposViejo = $this->viejo->camposExistentes('product.product', ['type', 'is_storable', 'detailed_type', 'tracking']);
        $this->tipoCamposNuevo = $this->nuevo->camposExistentes('product.product', ['type', 'is_storable', 'detailed_type', 'tracking']);

        $this->paso('Categorías');
        [$catsViejo, $mapCat] = $this->categorias();

        $this->paso('Leyendo productos de los dos Odoo');
        $campos = array_merge(CatalogoLocal::CAMPOS_PRODUCTO, ['qty_available', 'uom_id']);
        $pv = array_column($this->viejo->searchReadTodo('product.product', [['active', '=', true]], array_merge($campos, $this->tipoCamposViejo)), null, 'id');
        $pn = array_column($this->nuevo->searchReadTodo('product.product', [['active', '=', true]], array_merge($campos, $this->tipoCamposNuevo)), null, 'id');
        foreach ($pn as $p) if ($p['barcode']) $this->barcodesNuevo[trim($p['barcode'])] = $p['id'];   // código de barras => producto del nuevo

        $this->paso('Emparejando productos');
        $pares = $this->emparejar($pv, $pn);

        $creados = $this->crearFaltantes($pv, $pares, $mapCat);
        $this->actualizar($pv, $pn, $pares, $creados, $mapCat);
        $this->stock($pv, $pn, $pares, $creados);

        $this->paso('Guardando las listas locales');
        CatalogoLocal::guardar('sqlite_viejo', $catsViejo, array_values($pv));
        CatalogoLocal::actualizar($this->nuevo, Instancia::conexionPrincipal());

        $this->resumen['segundos'] = (int) round(microtime(true) - $inicio);
        $this->resumen['productos_viejo'] = count($pv);
        $this->resumen['detalle_errores'] = array_slice($this->resumen['detalle_errores'], 0, 50);
        return $this->resumen;
    }

    // ---------------------------------------------------------------- categorías

    /** Crea en el nuevo las categorías que faltan. Devuelve [categorías del viejo, id viejo => id nuevo]. */
    private function categorias(): array
    {
        $campos = ['id', 'name', 'complete_name', 'parent_id'];
        $cv = $this->viejo->searchRead('product.category', [], $campos);
        $porClave = [];
        foreach ($this->nuevo->searchRead('product.category', [], $campos) as $c) {
            $porClave[CatalogoLocal::claveCategoria($c['complete_name'])] ??= $c['id'];
        }

        // de a niveles: primero las de arriba, así cada una encuentra a su padre ya creado
        $niveles = [];
        foreach ($cv as $c) $niveles[substr_count($c['complete_name'], '/')][] = $c;
        ksort($niveles);

        $map = [];
        foreach ($niveles as $nivel) {
            $crear = [];
            foreach ($nivel as $c) {
                $clave = CatalogoLocal::claveCategoria($c['complete_name']);
                if (isset($porClave[$clave])) { $map[$c['id']] = $porClave[$clave]; continue; }
                $padre = is_array($c['parent_id']) ? ($map[$c['parent_id'][0]] ?? false) : false;
                $crear[] = [$c, $clave, ['name' => $c['name'], 'parent_id' => $padre]];
            }
            if (!$crear) continue;
            $ids = $this->nuevo->crear('product.category', array_column($crear, 2));
            foreach ($crear as $i => [$c, $clave]) {
                $map[$c['id']] = $porClave[$clave] = $ids[$i];
            }
            $this->resumen['categorias_creadas'] += count($crear);
        }
        return [$cv, $map];
    }

    // ---------------------------------------------------------------- productos

    private static function claves(array $p): array
    {
        $codigo = mb_strtoupper(trim((string) ($p['default_code'] ?: '')));
        $nombre = Matcher::normalizar((string) $p['name']);
        return [
            'barcode' => trim((string) ($p['barcode'] ?: '')) ?: null,
            'codigo'  => $codigo !== '' ? $codigo : null,
            'nombre'  => $nombre !== '' ? $nombre : null,
        ];
    }

    /**
     * Cada producto del viejo con su producto del nuevo: primero por código de barras, después por referencia
     * y por último por nombre exacto. Si una clave apunta a varios productos libres del nuevo, no se adivina.
     * @return array<int,int> id viejo => id nuevo
     */
    private function emparejar(array $pv, array $pn): array
    {
        $tipos = ['barcode', 'codigo', 'nombre'];
        $indice = array_fill_keys($tipos, []);
        foreach ($pn as $p) {
            foreach (self::claves($p) as $tipo => $k) if ($k !== null) $indice[$tipo][$k][] = $p['id'];
        }
        $clavesViejo = array_map(fn ($p) => self::claves($p), $pv);

        $pares = [];
        $usados = [];
        foreach ($tipos as $tipo) {
            foreach ($clavesViejo as $vid => $k) {
                if (isset($pares[$vid]) || $k[$tipo] === null) continue;
                $libres = array_values(array_filter($indice[$tipo][$k[$tipo]] ?? [], fn ($id) => !isset($usados[$id])));
                if (count($libres) !== 1) continue;
                $pares[$vid] = $libres[0];
                $usados[$libres[0]] = true;
            }
        }
        return $pares;
    }

    private function tipo(array $p): string
    {
        $t = $p['type'] ?? 'consu';
        if ($t === 'service') return 'servicio';
        if (!empty($p['is_storable']) || ($p['detailed_type'] ?? null) === 'product' || $t === 'product') return 'almacenable';
        return 'consumible';
    }

    private function valoresTipo(string $tipo): array
    {
        $c = $this->tipoCamposNuevo;
        if (in_array('is_storable', $c, true)) {
            return $tipo === 'servicio' ? ['type' => 'service'] : ['type' => 'consu', 'is_storable' => $tipo === 'almacenable'];
        }
        $v = ['servicio' => 'service', 'almacenable' => 'product', 'consumible' => 'consu'][$tipo];
        return in_array('detailed_type', $c, true) ? ['detailed_type' => $v] : ['type' => $v];
    }

    /** Unidad de medida del viejo => la del nuevo con el mismo nombre. */
    private function mapaUnidades(): array
    {
        try {
            $nuevo = [];
            foreach ($this->nuevo->searchRead('uom.uom', [], ['id', 'name']) as $u) $nuevo[mb_strtolower(trim($u['name']))] ??= $u['id'];
            $map = [];
            foreach ($this->viejo->searchRead('uom.uom', [], ['id', 'name']) as $u) {
                if ($id = $nuevo[mb_strtolower(trim($u['name']))] ?? null) $map[$u['id']] = $id;
            }
            return $map;
        } catch (\Throwable) {
            return [];
        }
    }

    /** Crea en el nuevo los productos del viejo que no tienen pareja. Devuelve [id nuevo => true] de los creados. */
    private function crearFaltantes(array $pv, array &$pares, array $mapCat): array
    {
        $faltan = array_values(array_filter($pv, fn ($p) => !isset($pares[$p['id']])));
        $total = count($faltan);
        $this->paso('Creando productos que faltan', 0, $total);
        if (!$total) return [];

        $unidades = $this->mapaUnidades();
        $lotes = [];
        foreach (array_chunk($faltan, self::LOTE_CREAR) as $i => $lote) {
            $lotes[$i] = [$lote, array_map(fn ($p) => $this->valoresNuevos($p, $mapCat, $unidades), $lote)];
        }

        $creados = [];
        $hechos = 0;
        // de a 2 lotes simultáneos (Odoo.com frena si se le mandan muchos pedidos a la vez)
        foreach (array_chunk($lotes, 2, true) as $grupo) {
            $res = $this->nuevo->callMany('product.product', 'create', array_map(fn ($l) => [[$l[1]]], $grupo), 2);
            foreach ($grupo as $i => [$lote, $valores]) {
                $ids = $res[$i];
                // si el lote entero falla (un código de barras repetido, por ejemplo) se crean de a uno para salvar el resto
                $ids = $ids instanceof \Throwable ? $this->crearDeAUno($lote, $valores) : array_map('intval', (array) $ids);
                foreach ($lote as $j => $p) {
                    if (!($ids[$j] ?? null)) continue;
                    $pares[$p['id']] = $ids[$j];
                    $creados[$ids[$j]] = true;
                    $this->resumen['productos_creados']++;
                }
                $hechos += count($lote);
            }
            $this->paso('Creando productos que faltan', $hechos, $total);
        }
        return $creados;
    }

    private function valoresNuevos(array $p, array $mapCat, array $unidades): array
    {
        $barcode = trim((string) ($p['barcode'] ?: ''));
        if ($barcode !== '' && isset($this->barcodesNuevo[$barcode])) $barcode = '';
        if ($barcode !== '') $this->barcodesNuevo[$barcode] = -1;   // lo va a tener un producto que se está creando
        $cat = is_array($p['categ_id']) ? ($mapCat[$p['categ_id'][0]] ?? null) : null;
        $uom = is_array($p['uom_id'] ?? null) ? ($unidades[$p['uom_id'][0]] ?? null) : null;

        return array_filter([
            'name'           => $p['name'],
            'standard_price' => (float) $p['standard_price'],
            'list_price'     => (float) $p['list_price'],
            'categ_id'       => $cat,
            'default_code'   => $p['default_code'] ?: null,
            'barcode'        => $barcode ?: null,
            'uom_id'         => $uom,
        ], fn ($v) => $v !== null) + $this->valoresTipo($this->tipo($p));
    }

    /** @return array<int,int|null> */
    private function crearDeAUno(array $lote, array $valores): array
    {
        $ids = [];
        foreach ($lote as $j => $p) {
            $v = $valores[$j];
            try {
                $ids[$j] = $this->nuevo->crear('product.product', [$v])[0];
            } catch (\Throwable $e) {
                // se reintenta sin código de barras ni unidad (lo más común que choca)
                try {
                    unset($v['barcode'], $v['uom_id']);
                    $ids[$j] = $this->nuevo->crear('product.product', [$v])[0];
                } catch (\Throwable $e2) {
                    $ids[$j] = null;
                    $this->error("No se pudo crear «{$p['name']}»: " . $e2->getMessage());
                }
            }
        }
        return $ids;
    }

    /** Nombre, códigos, costo, venta y categoría del viejo en los productos que ya estaban en el nuevo. */
    private function actualizar(array $pv, array $pn, array $pares, array $creados, array $mapCat): void
    {
        $cambiosPorId = [];
        foreach ($pares as $vid => $nid) {
            if (isset($creados[$nid])) continue;
            $v = $pv[$vid];
            $n = $pn[$nid];
            $cambios = [];
            if (abs((float) $v['standard_price'] - (float) $n['standard_price']) >= 0.005) $cambios['standard_price'] = (float) $v['standard_price'];
            if (abs((float) $v['list_price'] - (float) $n['list_price']) >= 0.005) $cambios['list_price'] = (float) $v['list_price'];
            $cat = is_array($v['categ_id']) ? ($mapCat[$v['categ_id'][0]] ?? null) : null;
            if ($cat && $cat !== (is_array($n['categ_id']) ? $n['categ_id'][0] : null)) $cambios['categ_id'] = $cat;
            // el nuevo queda igual al viejo: nombre y códigos también se pisan
            if (trim((string) $v['name']) !== trim((string) $n['name'])) $cambios['name'] = $v['name'];
            if ((string) ($v['default_code'] ?: '') !== (string) ($n['default_code'] ?: '')) $cambios['default_code'] = $v['default_code'] ?: false;
            $bc = trim((string) ($v['barcode'] ?: ''));
            $bcNuevo = trim((string) ($n['barcode'] ?: ''));
            if ($bc !== $bcNuevo) {
                $duenio = $bc !== '' ? ($this->barcodesNuevo[$bc] ?? null) : null;
                // si otro producto del nuevo ya tiene ese código de barras, Odoo no deja repetirlo: se deja como está
                if ($duenio === null || $duenio === $nid) {
                    $cambios['barcode'] = $bc !== '' ? $bc : false;
                    if ($bcNuevo !== '') unset($this->barcodesNuevo[$bcNuevo]);
                    if ($bc !== '') $this->barcodesNuevo[$bc] = $nid;
                }
            }

            if (!$cambios) { $this->resumen['sin_cambios']++; continue; }
            $cambiosPorId[$nid] = $cambios;
        }

        // de a 200 productos por llamada (importación de Odoo): pocas llamadas, sin pasarse del límite de Odoo.com
        $total = count($cambiosPorId);
        $paso = 'Actualizando nombre, códigos, costo, venta y categoría';
        $this->paso($paso, 0, $total);
        $fallas = $this->nuevo->escribirVarios('product.product', $cambiosPorId, 200, fn ($h) => $this->paso($paso, $h, $total));
        foreach ($fallas as $nid => $e) $this->error('Al actualizar «' . ($pn[$nid]['name'] ?? $nid) . '»: ' . $e);
        $this->resumen['productos_actualizados'] += $total - count($fallas);
    }

    // ---------------------------------------------------------------- stock

    /** Deja el disponible de cada producto almacenable igual al del viejo. */
    private function stock(array $pv, array $pn, array $pares, array $creados): void
    {
        $deltas = [];
        foreach ($pares as $vid => $nid) {
            $v = $pv[$vid];
            if ($this->tipo($v) !== 'almacenable') continue;
            if (($v['tracking'] ?? 'none') !== 'none') continue;   // con lote / número de serie no se puede ajustar así
            if (!isset($creados[$nid]) && $this->tipo($pn[$nid]) !== 'almacenable') continue;
            $actual = isset($creados[$nid]) ? 0.0 : (float) ($pn[$nid]['qty_available'] ?? 0);
            $delta = round((float) $v['qty_available'] - $actual, 4);
            if (abs($delta) >= 0.0001) $deltas[$nid] = $delta;
        }
        $total = count($deltas);
        $this->paso('Ajustando stock', 0, $total);
        if (!$total) return;

        $loc = $this->nuevo->ubicacionStock();
        // lo que ya hay en la ubicación principal (sin lote ni paquete): ahí se suma la diferencia
        $enUbicacion = [];
        foreach (array_chunk(array_keys($deltas), 2000) as $ids) {
            $quants = $this->nuevo->searchRead('stock.quant', [
                ['location_id', '=', $loc], ['product_id', 'in', $ids],
                ['lot_id', '=', false], ['package_id', '=', false], ['owner_id', '=', false],
            ], ['product_id', 'quantity']);
            foreach ($quants as $q) $enUbicacion[$q['product_id'][0]] = ($enUbicacion[$q['product_id'][0]] ?? 0) + (float) $q['quantity'];
        }
        $objetivo = [];
        foreach ($deltas as $nid => $d) $objetivo[$nid] = round(($enUbicacion[$nid] ?? 0) + $d, 4);

        $autoAplicar = in_array('inventory_quantity_auto_apply', $this->nuevo->camposExistentes('stock.quant', ['inventory_quantity_auto_apply']), true);
        if (!$autoAplicar) {
            // Odoo sin "aplicar al guardar": ajuste de a uno (más lento)
            $hechos = 0;
            foreach (array_chunk($deltas, 50, true) as $lote) {
                $errores = $this->nuevo->sumarStock($lote);
                foreach ($errores as $pid => $e) $this->error("Stock del producto $pid: $e");
                $this->resumen['stock_ajustado'] += count($lote) - count($errores);
                $hechos += count($lote);
                $this->paso('Ajustando stock', $hechos, $total);
            }
            return;
        }

        // en modo inventario, crear un quant con inventory_quantity_auto_apply busca el existente y aplica el ajuste en el momento
        $ctx = ['context' => ['inventory_mode' => true]];
        $lotes = array_chunk($objetivo, self::LOTE_STOCK, true);
        $hechos = 0;
        foreach (array_chunk($lotes, 2, true) as $grupo) {
            $llamadas = array_map(fn ($lote) => [[array_map(
                fn ($nid) => ['product_id' => $nid, 'location_id' => $loc, 'inventory_quantity_auto_apply' => $lote[$nid]],
                array_keys($lote)
            )], $ctx], $grupo);
            foreach ($this->nuevo->callMany('stock.quant', 'create', $llamadas, 2) as $i => $r) {
                if (!$r instanceof \Throwable) {
                    $this->resumen['stock_ajustado'] += count($grupo[$i]);
                    continue;
                }
                // el lote falló: de a uno, para que un producto con problema no frene a los demás
                foreach ($grupo[$i] as $nid => $cant) {
                    try {
                        $this->nuevo->crear('stock.quant', [['product_id' => $nid, 'location_id' => $loc, 'inventory_quantity_auto_apply' => $cant]], $ctx);
                        $this->resumen['stock_ajustado']++;
                    } catch (\Throwable $e) {
                        $this->error("Stock del producto «" . ($pn[$nid]['name'] ?? $nid) . "»: " . $e->getMessage());
                    }
                }
            }
            $hechos += array_sum(array_map('count', $grupo));
            $this->paso('Ajustando stock', $hechos, $total);
        }
    }

    // ---------------------------------------------------------------- auxiliares

    private function paso(string $texto, int $hechos = 0, int $total = 0): void
    {
        if ($this->avance) ($this->avance)($texto, $hechos, $total);
    }

    private function error(string $mensaje): void
    {
        $this->resumen['errores']++;
        if (count($this->resumen['detalle_errores']) < 200) $this->resumen['detalle_errores'][] = mb_substr($mensaje, 0, 400);
    }
}
