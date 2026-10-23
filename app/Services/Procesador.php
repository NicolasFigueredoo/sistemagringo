<?php

namespace App\Services;

use App\Models\Carga;
use App\Models\CargaItem;
use App\Models\Categoria;
use App\Models\Mapeo;
use App\Models\OdooProducto;
use App\Models\Proveedor;
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
                ['id', 'name', 'barcode', 'default_code', 'standard_price', 'list_price', 'categ_id'],
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
                    'created_at'   => $ahora,
                    'updated_at'   => $ahora,
                ];
            }
            foreach (array_chunk($datos, 500) as $chunk) {
                OdooProducto::upsert($chunk, ['id'], ['nombre', 'nombre_norm', 'barcode', 'default_code', 'costo', 'precio', 'categ_id', 'updated_at']);
            }

            $total += count($rows);
            $offset += $lote;
            if ($progreso) $progreso($total);
        } while (count($rows) === $lote);

        if ($vistos) {
            OdooProducto::whereNotIn('id', $vistos)->delete();
        }

        try {
            $this->sincronizarProveedores();
        } catch (\Throwable) {
            // los proveedores no deben frenar la sincronización del catálogo
        }

        return $total;
    }

    /** Trae los proveedores de Odoo (una sola consulta) y agrega los que falten. Devuelve cuántos agregó. */
    public function sincronizarProveedores(): int
    {
        $partners = $this->odoo->searchRead(
            'res.partner',
            [['supplier_rank', '>', 0], ['active', '=', true], ['parent_id', '=', false]],
            ['name']
        );

        $existentes = Proveedor::pluck('nombre')->map(fn ($n) => mb_strtolower(trim($n)))->flip();
        $ahora = now();
        $nuevos = [];
        foreach ($partners as $p) {
            $nombre = trim((string) ($p['name'] ?? ''));
            $clave = mb_strtolower($nombre);
            if ($nombre === '' || isset($existentes[$clave])) continue;
            $existentes[$clave] = true;
            $nuevos[] = ['nombre' => mb_substr($nombre, 0, 250), 'created_at' => $ahora, 'updated_at' => $ahora];
        }
        foreach (array_chunk($nuevos, 500) as $chunk) {
            Proveedor::insert($chunk);
        }
        return count($nuevos);
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
        $matcher = new Matcher(
            OdooProducto::query()->select('id', 'nombre', 'barcode', 'default_code')->cursor()->map(fn ($p) => $p->toArray()),
            $mapeos
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

                $m = $matcher->buscar($barcode, $codigo, $nombre);

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
                    'seleccionado'    => in_array($m['match'], ['barcode', 'mapeo'], true),
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
     * Arma una carga para revisar con todos los productos de la categoría:
     * precio de venta = costo actual en Odoo + ganancia de la categoría (+ IVA, redondeo).
     * Los productos sin costo en Odoo se saltean. Devuelve [carga, salteados].
     */
    public function crearAjusteCategoria(Categoria $cat, bool $sumarIva = true, int $redondeo = 0): array
    {
        @set_time_limit(0);
        $margen = (float) $cat->margen;
        $productos = OdooProducto::where('categ_id', $cat->id)->orderBy('nombre')->get(['id', 'nombre', 'barcode', 'default_code']);

        // costo y precio actuales, leídos de Odoo (el catálogo local puede estar viejo)
        $actual = [];
        foreach ($productos->chunk(500) as $chunk) {
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

            $items[] = [
                'fila'            => count($items) + 1,
                'barcode'         => $p->barcode,
                'codigo'          => $p->default_code,
                'nombre'          => $p->nombre,
                'precio_lista'    => $costo,
                'categoria_id'    => $cat->id,
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

        $carga = DB::transaction(function () use ($cat, $items, $sumarIva, $redondeo) {
            $carga = Carga::create([
                'proveedor_id' => null,
                'categoria_id' => $cat->id,
                'archivo'      => "Ganancia {$cat->margen}% sobre el costo actual",
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
        $items = $q->get();

        foreach ($items->chunk(500) as $chunk) {
            $ids = $chunk->pluck('odoo_product_id')->unique()->values()->all();
            $actual = collect($this->odoo->read('product.product', $ids, ['standard_price', 'list_price']))->keyBy('id');
            foreach ($chunk as $it) {
                if ($a = $actual->get($it->odoo_product_id)) {
                    $it->update(['costo_anterior' => $a['standard_price'], 'precio_anterior' => $a['list_price']]);
                }
            }
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

        $n = 0;
        foreach ($carga->items()->where('categoria_id', $categoriaId)->get() as $it) {
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
            $n++;
        }
        return $n;
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
        $this->traerPreciosActuales($carga, $items->pluck('id')->all());
        $items = $items->fresh();

        $ok = 0; $errores = 0;
        foreach ($items as $it) {
            try {
                $campos = $this->camposOdoo($carga, $it->costo_nuevo, $it->precio_nuevo);
                $this->odoo->write('product.product', [$it->odoo_product_id], $campos);
                $it->update(['aplicado' => true, 'error' => null]);
                OdooProducto::where('id', $it->odoo_product_id)->update(array_filter(['costo' => $campos['standard_price'] ?? null, 'precio' => $campos['list_price']], fn ($v) => $v !== null));

                // si lo confirmó una persona, recordarlo para la próxima
                if (in_array($it->match, ['manual', 'nombre'], true)) {
                    foreach (Matcher::claves($it->barcode, $it->codigo, $it->nombre) as $clave) {
                        Mapeo::updateOrCreate(
                            ['proveedor_id' => $carga->proveedor_id, 'clave' => mb_substr($clave, 0, 250)],
                            ['odoo_product_id' => $it->odoo_product_id]
                        );
                    }
                }
                $ok++;
            } catch (\Throwable $e) {
                $it->update(['error' => mb_substr($e->getMessage(), 0, 250)]);
                $errores++;
            }
        }

        $carga->update(['estado' => 'aplicada', 'aplicada_at' => now()]);
        return [$ok, $errores];
    }

    /** Vuelve a poner en Odoo los precios que había antes de esta carga. */
    public function deshacer(Carga $carga): int
    {
        @set_time_limit(0);
        $n = 0;
        foreach ($carga->items()->where('aplicado', true)->get() as $it) {
            if ($it->costo_anterior === null || $it->precio_anterior === null) continue;
            $campos = $this->camposOdoo($carga, $it->costo_anterior, $it->precio_anterior);
            $this->odoo->write('product.product', [$it->odoo_product_id], $campos);
            OdooProducto::where('id', $it->odoo_product_id)->update(array_filter(['costo' => $campos['standard_price'] ?? null, 'precio' => $campos['list_price']], fn ($v) => $v !== null));
            $it->update(['aplicado' => false]);
            $n++;
        }
        $carga->update(['estado' => 'deshecha', 'deshecha_at' => now()]);
        return $n;
    }
}
