<?php

namespace App\Services;

use App\Models\Carga;
use App\Models\CargaItem;
use App\Models\Categoria;
use App\Models\CodigoProveedor;
use App\Models\Mapeo;
use App\Models\OdooProducto;
use App\Models\Proveedor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Prioridad para calcular cada precio:
 *   1. precio escrito a mano en la revisión
 *   2. % de la categoría de Odoo del producto (sección Categorías o bloque "Por categoría")
 *   3. % del proveedor / descuento general de la carga
 */
class Procesador
{
    public function __construct(private OdooClient $odoo) {}

    /** Baja categorías y productos de Odoo a las tablas locales. */
    public function sincronizarCatalogo(?callable $progreso = null): int
    {
        // categorías (respeta los % ya cargados)
        $cats = $this->odoo->searchRead('product.category', [], ['id', 'complete_name']);
        $ahora = now();
        $filas = array_map(fn ($c) => [
            'id' => $c['id'], 'nombre' => mb_substr($c['complete_name'] ?: '—', 0, 250), 'created_at' => $ahora, 'updated_at' => $ahora,
        ], $cats);
        foreach (array_chunk($filas, 500) as $chunk) {
            Categoria::upsert($chunk, ['id'], ['nombre', 'updated_at']);
        }

        $total = 0;
        $offset = 0;
        $lote = 2000;
        $vistos = [];

        do {
            $rows = $this->odoo->searchRead(
                'product.product',
                [['active', '=', true]],
                ['id', 'name', 'barcode', 'default_code', 'standard_price', 'list_price', 'categ_id', 'product_tmpl_id'],
                $offset,
                $lote
            );

            $datos = [];
            foreach ($rows as $p) {
                $vistos[] = $p['id'];
                $datos[] = [
                    'id'           => $p['id'],
                    'nombre'       => mb_substr($p['name'], 0, 250),
                    'nombre_norm'  => mb_substr(Matcher::normalizar($p['name']), 0, 250),
                    'barcode'      => $p['barcode'] ?: null,
                    'default_code' => $p['default_code'] ?: null,
                    'costo'        => $p['standard_price'] ?? 0,
                    'precio'       => $p['list_price'] ?? 0,
                    'categ_id'     => is_array($p['categ_id']) ? $p['categ_id'][0] : null,   // many2one = [id, nombre]
                    'product_tmpl_id' => is_array($p['product_tmpl_id'] ?? null) ? $p['product_tmpl_id'][0] : null,
                    'created_at'   => $ahora,
                    'updated_at'   => $ahora,
                ];
            }
            DB::transaction(function () use ($datos) {
                foreach (array_chunk($datos, 500) as $chunk) {
                    OdooProducto::upsert($chunk, ['id'], ['nombre', 'nombre_norm', 'barcode', 'default_code', 'costo', 'precio', 'categ_id', 'product_tmpl_id', 'updated_at']);
                }
            });

            $total += count($rows);
            $offset += $lote;
            if ($progreso) $progreso($total);
        } while (count($rows) === $lote);

        if ($vistos) {
            OdooProducto::whereNotIn('id', $vistos)->delete();
        }

        // los proveedores y sus códigos no deben frenar la sincronización del catálogo
        try {
            $this->sincronizarProveedores();
        } catch (\Throwable) {
        }
        try {
            $this->sincronizarCodigosProveedor();
        } catch (\Throwable $e) {
            report($e);
        }

        return $total;
    }

    /** Trae los proveedores de Odoo (una sola consulta), agrega los que falten y los vincula con su contacto. Devuelve cuántos agregó. */
    public function sincronizarProveedores(): int
    {
        $partners = $this->odoo->searchRead(
            'res.partner',
            [['supplier_rank', '>', 0], ['active', '=', true], ['parent_id', '=', false]],
            ['id', 'name']
        );

        $existentes = Proveedor::get(['id', 'nombre', 'odoo_partner_id'])->keyBy(fn ($p) => mb_strtolower(trim($p->nombre)));
        $ahora = now();
        $nuevos = [];
        DB::transaction(function () use ($partners, $existentes, $ahora, &$nuevos) {
            foreach ($partners as $p) {
                $nombre = trim((string) ($p['name'] ?? ''));
                $clave = mb_strtolower($nombre);
                if ($nombre === '') continue;
                if ($existentes->has($clave)) {
                    $prov = $existentes[$clave];
                    if ($prov && $prov->odoo_partner_id === null) $prov->update(['odoo_partner_id' => $p['id']]);
                    continue;
                }
                $existentes[$clave] = false;
                $nuevos[] = ['nombre' => mb_substr($nombre, 0, 250), 'odoo_partner_id' => $p['id'], 'created_at' => $ahora, 'updated_at' => $ahora];
            }
            foreach (array_chunk($nuevos, 500) as $chunk) {
                Proveedor::insert($chunk);
            }
        });
        return count($nuevos);
    }

    /** Baja los códigos que usa cada proveedor para cada producto (pestaña Compra del producto en Odoo). */
    public function sincronizarCodigosProveedor(): int
    {
        // un código cargado en la plantilla vale para todas sus variantes
        $variantes = OdooProducto::whereNotNull('product_tmpl_id')->get(['id', 'product_tmpl_id'])
            ->groupBy('product_tmpl_id')->map(fn ($g) => $g->pluck('id')->all());

        $filas = [];
        $offset = 0;
        $lote = 5000;
        do {
            $rows = $this->odoo->searchRead(
                'product.supplierinfo',
                [['product_code', '!=', false]],
                ['partner_id', 'product_code', 'product_id', 'product_tmpl_id'],
                $offset,
                $lote
            );
            foreach ($rows as $r) {
                $codigo = Matcher::codigoClave($r['product_code'] ?: null);
                if (!$codigo || !is_array($r['partner_id'])) continue;
                $ids = is_array($r['product_id']) ? [$r['product_id'][0]]
                    : (is_array($r['product_tmpl_id']) ? ($variantes[$r['product_tmpl_id'][0]] ?? []) : []);
                foreach ($ids as $id) {
                    $filas[] = ['partner_id' => $r['partner_id'][0], 'codigo' => mb_substr($codigo, 0, 250), 'product_id' => $id];
                }
            }
            $offset += $lote;
        } while (count($rows) === $lote);

        DB::transaction(function () use ($filas) {
            CodigoProveedor::query()->delete();
            foreach (array_chunk($filas, 500) as $chunk) {
                CodigoProveedor::insert($chunk);
            }
        });
        return count($filas);
    }

    /** Posibles códigos de un renglón fuera de las columnas elegidas: otras columnas y el principio de la descripción. */
    private static function codigosExtra(array $fila, array $columnas, ?string $nombre): array
    {
        $usadas = array_filter([$columnas['col_barcode'], $columnas['col_codigo'], $columnas['col_nombre'], $columnas['col_precio']], fn ($c) => $c !== null);
        $out = [];
        foreach ($fila as $i => $v) {
            $v = trim((string) $v);
            if (in_array($i, $usadas) || mb_strlen($v) < 3 || mb_strlen($v) > 30 || !preg_match('/\d/', $v)) continue;
            // cantidades y precios ("1", "12", "1.500,00") no son códigos
            if (preg_match('/^\d{1,3}([.,]\d{3})*([.,]\d{1,2})?$/', $v)) continue;
            if (preg_match('/^[\pL\d][\pL\d.\/ _-]*$/u', $v)) $out[] = $v;
        }
        // "ABC-12  45678 DESCRIPCION": el segundo código queda pegado a la descripción
        $palabras = preg_split('/\s+/', trim((string) $nombre));
        for ($n = 1; $n <= min(3, count($palabras) - 1); $n++) {
            $pre = implode(' ', array_slice($palabras, 0, $n));
            if (preg_match('/\d/', $pre)) $out[] = $pre;
        }
        return $out;
    }

    /**
     * Lee el archivo, busca cada producto y arma la carga para revisar.
     * @param array $columnas fila_inicio, col_barcode, col_codigo, col_nombre, col_precio
     */
    public function crearCarga(Proveedor $prov, string $ruta, string $ext, string $nombreArchivo, array $columnas, array $opciones): Carga
    {
        @set_time_limit(0);
        $filas = LectorLista::leer($ruta, $ext);

        $mapeos = Mapeo::where('proveedor_id', $prov->id)->pluck('odoo_product_id', 'clave')->all();
        // códigos del proveedor en Odoo; si no se sabe qué contacto de Odoo es, los de todos (hay que confirmarlos)
        $codigosProv = CodigoProveedor::query()->select('codigo', 'product_id')
            ->when($prov->odoo_partner_id, fn ($q) => $q->where('partner_id', $prov->odoo_partner_id))
            ->toBase()->get();
        $matcher = new Matcher(
            OdooProducto::query()->select('id', 'nombre', 'barcode', 'default_code')->toBase()->cursor(),
            $mapeos,
            $codigosProv,
            (bool) $prov->odoo_partner_id
        );

        $carga = DB::transaction(function () use ($prov, $filas, $columnas, $opciones, $matcher, $nombreArchivo) {
            $carga = Carga::create([
                'proveedor_id' => $prov->id,
                'archivo'      => $nombreArchivo,
                'descuento'    => (float) $opciones['descuento'],
                'iva_incluido' => (bool) $opciones['iva_incluido'],
                'sumar_iva'    => (bool) $opciones['sumar_iva'],
                'redondeo'     => (int) $opciones['redondeo'],
            ]);

            $celda = fn (array $f, $col) => ($col === null || $col === '') ? null : ($f[(int) $col] ?? null);
            $inicio = max(1, (int) $columnas['fila_inicio']) - 1;
            $items = [];

            foreach (array_slice($filas, $inicio, null, true) as $i => $f) {
                $precio = Precios::numero($celda($f, $columnas['col_precio']) ?? '');
                $barcode = $celda($f, $columnas['col_barcode']);
                $codigo = $celda($f, $columnas['col_codigo']);
                $nombre = $celda($f, $columnas['col_nombre']);

                if (!$precio || $precio <= 0) continue;            // títulos, subtotales, etc.
                if (!$barcode && !$codigo && !$nombre) continue;

                $m = $matcher->buscar($barcode, $codigo, $nombre, self::codigosExtra($f, $columnas, $nombre));

                $items[] = [
                    'carga_id'        => $carga->id,
                    'fila'            => $i + 1,
                    'barcode'         => $barcode ? mb_substr($barcode, 0, 250) : null,
                    'codigo'          => $codigo ? mb_substr($codigo, 0, 250) : null,
                    'nombre'          => $nombre ? mb_substr($nombre, 0, 250) : null,
                    'unidad'          => isset($f[3]) && $columnas['col_precio'] == 4 ? mb_substr((string) $f[3], 0, 50) : null,
                    'precio_lista'    => $precio,
                    'descuento'       => $carga->descuento,
                    'costo_nuevo'     => 0,
                    'match'           => $m['match'],
                    'candidatos'      => json_encode($m['candidatos']),
                    'odoo_product_id' => $m['odoo_product_id'],
                    'odoo_nombre'     => $m['odoo_nombre'],
                    'margen'          => (float) $prov->margen,
                    'precio_nuevo'    => 0,
                    'seleccionado'    => in_array($m['match'], ['barcode', 'mapeo', 'codigo'], true),
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ];
            }

            foreach (array_chunk($items, 500) as $chunk) {
                CargaItem::insert($chunk);
            }
            return $carga;
        });

        // categoría de cada producto encontrado + cuentas
        $this->aplicarReglas($carga);
        $this->traerPreciosActuales($carga);
        return $carga;
    }

    /**
     * Arma una carga para revisar con los productos de la categoría (o de todas las que tienen ganancia, si $cat es null):
     * precio de venta = costo actual en Odoo + ganancia de su categoría. Sin IVA: 1000 de costo con 50% = 1500.
     * Los productos sin costo en Odoo se saltean. Devuelve [carga, salteados].
     */
    public function crearAjuste(?Categoria $cat = null, bool $sumarIva = false, int $redondeo = 0): array
    {
        @set_time_limit(0);
        $margenes = ($cat ? collect([$cat]) : Categoria::whereNotNull('margen')->get())->pluck('margen', 'id');
        $productos = OdooProducto::whereIn('categ_id', $margenes->keys())->orderBy('nombre')->get(['id', 'nombre', 'barcode', 'default_code', 'categ_id']);

        // costo y precio actuales, leídos de Odoo (el catálogo local puede estar viejo)
        $actual = [];
        foreach ($productos->chunk(2000) as $chunk) {
            foreach ($this->odoo->read('product.product', $chunk->pluck('id')->values()->all(), ['standard_price', 'list_price']) as $a) {
                $actual[$a['id']] = $a;
            }
        }

        $items = [];
        $salteados = 0;
        foreach ($productos as $p) {
            $a = $actual[$p->id] ?? null;
            $costo = round((float) ($a['standard_price'] ?? 0), 2);
            if (!$a || $costo <= 0) { $salteados++; continue; }   // sin costo: daría precio 0
            $margen = (float) $margenes[$p->categ_id];

            $items[] = [
                'fila'            => count($items) + 1,
                'barcode'         => $p->barcode,
                'codigo'          => $p->default_code,
                'nombre'          => $p->nombre,
                'precio_lista'    => $costo,
                'categoria_id'    => $p->categ_id,
                'descuento'       => 0,
                'costo_nuevo'     => $costo,
                'match'           => 'categoria',
                'candidatos'      => json_encode([]),
                'odoo_product_id' => $p->id,
                'odoo_nombre'     => $p->nombre,
                'costo_anterior'  => $a['standard_price'],
                'precio_anterior' => $a['list_price'],
                'margen'          => $margen,
                'precio_nuevo'    => Precios::venta($costo, $margen, $sumarIva, $redondeo),
                'seleccionado'    => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ];
        }

        $carga = DB::transaction(function () use ($cat, $margenes, $items, $sumarIva, $redondeo) {
            $carga = Carga::create([
                'proveedor_id' => null,
                'categoria_id' => $cat?->id,
                'archivo'      => $cat
                    ? 'Ganancia ' . ((float) $cat->margen + 0) . '% sobre el costo actual'
                    : "Ganancia de cada categoría sobre el costo actual ({$margenes->count()} categorías)",
                'descuento'    => 0,
                'iva_incluido' => false,
                'sumar_iva'    => $sumarIva,
                'redondeo'     => $redondeo,
            ]);
            foreach (array_chunk($items, 500) as $chunk) {
                CargaItem::insert(array_map(fn ($it) => $it + ['carga_id' => $carga->id], $chunk));
            }
            return $carga;
        });

        return [$carga, $salteados];
    }

    /** Campos que se escriben en Odoo: en un ajuste de categoría el costo no se toca. */
    private function camposOdoo(Carga $carga, $costo, $precio): array
    {
        return $carga->es_ajuste
            ? ['list_price' => (float) $precio]
            : ['standard_price' => (float) $costo, 'list_price' => (float) $precio];
    }

    /**
     * Recalcula categoría, descuento, costo, margen y precio de los renglones (salvo precios escritos a mano).
     * @param int[]|null $soloItemIds
     */
    public function aplicarReglas(Carga $carga, ?array $soloItemIds = null): void
    {
        $prov = $carga->proveedor;
        $categorias = Categoria::all()->keyBy('id');

        $q = $carga->items();
        if ($soloItemIds) $q->whereIn('id', $soloItemIds);

        foreach ($q->get()->chunk(500) as $chunk) {
            $prodCat = OdooProducto::whereIn('id', $chunk->pluck('odoo_product_id')->filter()->unique())->pluck('categ_id', 'id');

            DB::transaction(function () use ($chunk, $prodCat, $categorias, $carga, $prov) {
                foreach ($chunk as $it) {
                    $catId = $it->odoo_product_id ? ($prodCat[$it->odoo_product_id] ?? null) : null;
                    $cat = $catId ? $categorias->get($catId) : null;

                    $desc = $cat?->descuento !== null ? (float) $cat->descuento : (float) $carga->descuento;
                    $margen = $cat?->margen !== null ? (float) $cat->margen : (float) ($prov?->margen ?? $it->margen);
                    $costo = Precios::costo((float) $it->precio_lista, $desc, $carga->iva_incluido);

                    $datos = ['categoria_id' => $catId, 'descuento' => $desc, 'costo_nuevo' => $costo];
                    if ($it->precio_manual) {
                        $datos['margen'] = Precios::margenDesdePrecio($costo, (float) $it->precio_nuevo, $carga->sumar_iva);
                    } else {
                        $datos['margen'] = $margen;
                        $datos['precio_nuevo'] = Precios::venta($costo, $margen, $carga->sumar_iva, $carga->redondeo);
                    }
                    $it->update($datos);
                }
            });
        }
    }

    /** Lee de Odoo el costo y precio actual de los productos encontrados (para comparar y para deshacer). */
    public function traerPreciosActuales(Carga $carga, ?array $soloItemIds = null): void
    {
        $q = $carga->items()->whereNotNull('odoo_product_id');
        if ($soloItemIds) $q->whereIn('id', $soloItemIds);
        $this->refrescarAnteriores($q->get());
    }

    private function refrescarAnteriores(Collection $items): void
    {
        foreach ($items->chunk(2000) as $chunk) {
            $ids = $chunk->pluck('odoo_product_id')->unique()->values()->all();
            $actual = collect($this->odoo->read('product.product', $ids, ['standard_price', 'list_price']))->keyBy('id');
            DB::transaction(function () use ($chunk, $actual) {
                foreach ($chunk as $it) {
                    if ($a = $actual->get($it->odoo_product_id)) {
                        $it->update(['costo_anterior' => $a['standard_price'], 'precio_anterior' => $a['list_price']]);
                    }
                }
            });
        }
    }

    /** El usuario eligió a mano el producto correcto. */
    public function elegir(CargaItem $item, int $productoId): void
    {
        $p = OdooProducto::findOrFail($productoId);
        $item->update([
            'odoo_product_id' => $p->id,
            'odoo_nombre'     => $p->nombre,
            'match'           => 'manual',
            'seleccionado'    => true,
        ]);
        $this->aplicarReglas($item->carga, [$item->id]);
        $this->traerPreciosActuales($item->carga, [$item->id]);
    }

    /** % masivo / IVA / redondeo. Con $margenMasivo pisa todo (también categorías y precios a mano). */
    public function recalcular(Carga $carga, ?float $margenMasivo = null): void
    {
        DB::transaction(function () use ($carga, $margenMasivo) {
            if ($margenMasivo === null) {
                // cambió el IVA o el redondeo: rehacer la cuenta con el % que ya tiene cada fila
                foreach ($carga->items()->get() as $it) {
                    if ($it->precio_manual) {
                        $it->update(['margen' => Precios::margenDesdePrecio((float) $it->costo_nuevo, (float) $it->precio_nuevo, $carga->sumar_iva)]);
                    } else {
                        $it->update(['precio_nuevo' => Precios::venta((float) $it->costo_nuevo, (float) $it->margen, $carga->sumar_iva, $carga->redondeo)]);
                    }
                }
                return;
            }
            $carga->items()->update(['precio_manual' => false]);
            foreach ($carga->items()->get() as $it) {
                $it->update([
                    'margen'       => $margenMasivo,
                    'precio_nuevo' => Precios::venta((float) $it->costo_nuevo, $margenMasivo, $carga->sumar_iva, $carga->redondeo),
                ]);
            }
        });
    }

    /**
     * Aplica ganancia y/o descuento a todos los renglones de una categoría en esta carga.
     * Si $guardar = true, queda guardado en la categoría para las próximas cargas.
     */
    public function aplicarCategoria(Carga $carga, int $categoriaId, ?float $margen, ?float $descuento, bool $guardar): int
    {
        if ($guardar) {
            Categoria::where('id', $categoriaId)->update(['margen' => $margen, 'descuento' => $descuento]);
        }

        $items = $carga->items()->where('categoria_id', $categoriaId)->get();
        DB::transaction(function () use ($carga, $items, $margen, $descuento) {
            foreach ($items as $it) {
                $desc = $descuento ?? (float) $it->descuento;
                $costo = Precios::costo((float) $it->precio_lista, $desc, $carga->iva_incluido);
                $m = $margen ?? (float) $it->margen;
                $it->update([
                    'descuento'     => $desc,
                    'costo_nuevo'   => $costo,
                    'margen'        => $m,
                    'precio_nuevo'  => Precios::venta($costo, $m, $carga->sumar_iva, $carga->redondeo),
                    'precio_manual' => false,
                ]);
            }
        });
        return $items->count();
    }

    public function cambiarMargen(CargaItem $it, float $margen): void
    {
        $c = $it->carga;
        $it->update([
            'margen'        => $margen,
            'precio_nuevo'  => Precios::venta((float) $it->costo_nuevo, $margen, $c->sumar_iva, $c->redondeo),
            'precio_manual' => false,
        ]);
    }

    public function cambiarPrecio(CargaItem $it, float $precio): void
    {
        $it->update([
            'precio_nuevo'  => round($precio, 2),
            'margen'        => Precios::margenDesdePrecio((float) $it->costo_nuevo, $precio, $it->carga->sumar_iva),
            'precio_manual' => true,
        ]);
    }

    /** Escribe en Odoo. Devuelve [ok, errores]. */
    public function aplicar(Carga $carga): array
    {
        @set_time_limit(0);
        $items = $carga->items()->where('seleccionado', true)->where('aplicado', false)->whereNotNull('odoo_product_id')->get();

        // refrescar "anterior" justo antes de escribir, para que Deshacer sea exacto
        $this->refrescarAnteriores($items);

        $campos = $items->mapWithKeys(fn ($it) => [$it->id => $this->camposOdoo($carga, $it->costo_nuevo, $it->precio_nuevo)])->all();
        // los que en Odoo ya tienen esos valores no hace falta escribirlos
        $aEscribir = $items->reject(fn ($it) => self::igual($it->precio_anterior, $campos[$it->id]['list_price'])
            && (!isset($campos[$it->id]['standard_price']) || self::igual($it->costo_anterior, $campos[$it->id]['standard_price'])));
        $errores = $this->escribir($aEscribir, $campos);

        DB::transaction(function () use ($carga, $items, $campos, $errores) {
            foreach ($items as $it) {
                if (isset($errores[$it->id])) {
                    $it->update(['error' => mb_substr($errores[$it->id], 0, 250)]);
                    continue;
                }
                $it->update(['aplicado' => true, 'error' => null]);
                self::actualizarLocal($it->odoo_product_id, $campos[$it->id]);

                // si lo confirmó una persona, recordarlo para la próxima
                if (in_array($it->match, ['manual', 'nombre'], true)) {
                    foreach (Matcher::claves($it->barcode, $it->codigo, $it->nombre) as $clave) {
                        Mapeo::updateOrCreate(
                            ['proveedor_id' => $carga->proveedor_id, 'clave' => mb_substr($clave, 0, 250)],
                            ['odoo_product_id' => $it->odoo_product_id]
                        );
                    }
                }
            }
            $carga->update(['estado' => 'aplicada', 'aplicada_at' => now()]);
        });

        return [$items->count() - count($errores), count($errores)];
    }

    /** Vuelve a poner en Odoo los precios que había antes de esta carga. */
    public function deshacer(Carga $carga): int
    {
        @set_time_limit(0);
        $items = $carga->items()->where('aplicado', true)->whereNotNull('costo_anterior')->whereNotNull('precio_anterior')->get();
        $campos = $items->mapWithKeys(fn ($it) => [$it->id => $this->camposOdoo($carga, $it->costo_anterior, $it->precio_anterior)])->all();
        $errores = $this->escribir($items, $campos);

        DB::transaction(function () use ($carga, $items, $campos, $errores) {
            foreach ($items as $it) {
                if (isset($errores[$it->id])) continue;   // sigue aplicado: se puede volver a intentar
                self::actualizarLocal($it->odoo_product_id, $campos[$it->id]);
                $it->update(['aplicado' => false]);
            }
            if (!$errores) $carga->update(['estado' => 'deshecha', 'deshecha_at' => now()]);
        });
        if ($errores) {
            throw new \RuntimeException(count($errores) . ' productos no se pudieron volver atrás (tocá Deshacer de nuevo): ' . reset($errores));
        }
        return $items->count();
    }

    /**
     * Escribe en Odoo varios productos a la vez: junta los que llevan los mismos valores en un solo pedido
     * y manda varios pedidos en paralelo. Devuelve [item_id => mensaje de error].
     * @param array<int,array> $campos item_id => valores a escribir
     */
    private function escribir(Collection $items, array $campos): array
    {
        $grupos = $items->groupBy(fn ($it) => json_encode($campos[$it->id]))->values();
        $fallas = $this->odoo->writeMany('product.product', $grupos->map(fn ($g) => [
            $g->pluck('odoo_product_id')->unique()->values()->all(), $campos[$g->first()->id],
        ])->all());

        // Odoo rechaza el grupo entero si falla uno: esos se reintentan de a uno
        $sueltos = collect(array_keys($fallas))->flatMap(fn ($k) => $grupos[$k])->values();
        $errores = $this->odoo->writeMany('product.product', $sueltos->map(fn ($it) => [[$it->odoo_product_id], $campos[$it->id]])->all());

        $out = [];
        foreach ($errores as $k => $msg) $out[$sueltos[$k]->id] = $msg;
        return $out;
    }

    private static function actualizarLocal(int $productoId, array $campos): void
    {
        OdooProducto::where('id', $productoId)->update(array_filter(
            ['costo' => $campos['standard_price'] ?? null, 'precio' => $campos['list_price']],
            fn ($v) => $v !== null
        ));
    }

    private static function igual($a, $b): bool
    {
        return $a !== null && abs((float) $a - (float) $b) < 0.005;
    }
}
