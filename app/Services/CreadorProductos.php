<?php

namespace App\Services;

use App\Models\Carga;
use App\Models\CargaItem;
use App\Models\CodigoProveedor;
use App\Models\OdooProducto;
use Illuminate\Support\Collection;

/**
 * Crea en Odoo los productos de la carga que no existen, con el nombre, categoría, costo y venta de la revisión,
 * y el código del proveedor (pestaña Compra) o como referencia interna. Se crean en este Odoo y en el otro
 * (si en el otro ya existe, solo se vincula). Si el producto ya existe en uno de los dos, el que falta se crea
 * con sus datos (código de barras, referencia, venta). Lo llama "Actualizar en Odoo", antes de escribir costos y stock.
 */
class CreadorProductos
{
    public function __construct(private OdooClient $odoo, private ?OtroOdoo $otro) {}

    /**
     * @return array{0:array<int,string>,1:array<int,string>} [item_id => error (no se pudo crear en este Odoo), item_id => aviso (falló el otro)]
     */
    public function crear(Carga $carga, Collection $items): array
    {
        $errores = [];
        $avisosPorItem = [];
        $partner = $carga->proveedor?->odoo_partner_id;
        foreach ($items as $it) {
            $nombre = trim((string) ($it->crear_nombre ?: $it->nombre)) ?: 'Producto sin nombre';
            $avisos = [];

            // 1. en este Odoo (si no estaba)
            if (!$it->odoo_product_id) {
                try {
                    // ya existe en el otro Odoo: se copian su código de barras y su referencia
                    $modelo = $this->otro?->producto($it->otro_product_id ? (int) $it->otro_product_id : null);
                    $pid = $this->crearEn($this->odoo, $it, $nombre, $it->crear_categ_id, $partner, (float) $it->precio_nuevo, $modelo);
                    $p = $this->odoo->read('product.product', [$pid], ['product_tmpl_id', 'barcode', 'default_code'])[0] ?? [];
                    OdooProducto::upsert([self::filaLocal($pid, $nombre, $p, $it, $it->crear_categ_id)], ['id']);
                    if ($partner && $it->codigo && ($k = Matcher::codigoClave($it->codigo)) && ($it->guardar_codigo === 'proveedor')) {
                        CodigoProveedor::insert(['partner_id' => $partner, 'codigo' => mb_substr($k, 0, 250), 'product_id' => $pid]);
                    }
                    $it->update(['odoo_product_id' => $pid, 'odoo_nombre' => $nombre, 'match' => 'creado', 'costo_anterior' => null, 'precio_anterior' => null, 'stock_anterior' => 0, 'stock_aplicado' => false]);
                } catch (\Throwable $e) {
                    $errores[$it->id] = 'No se pudo crear: ' . $e->getMessage();
                    continue;
                }
            }

            // 2. en el otro Odoo: si ya existe se vincula, si no se crea igual
            if ($this->otro && !$it->otro_product_id) {
                try {
                    $existe = $this->otro->buscar($it, $carga->proveedor, OdooProducto::find($it->odoo_product_id));
                    if ($existe) {
                        $it->update(['otro_product_id' => $existe['id'], 'otro_nombre' => $existe['nombre']]);
                    } else {
                        $c = $this->otro->cliente();
                        $categ = $this->otro->categoria($it->crear_categ_id ?? $it->categoria_id);
                        // ya existía en este Odoo: se crea igual allá (nombre, venta, código de barras, referencia)
                        $modelo = $it->match === 'creado' ? null : OdooProducto::find($it->odoo_product_id);
                        $venta = $modelo && (float) $modelo->precio > 0 ? (float) $modelo->precio : (float) $it->precio_nuevo;
                        $oid = $this->crearEn($c, $it, $nombre, $categ, $this->otro->partner($carga->proveedor), $venta, $modelo);
                        $p = $c->read('product.product', [$oid], ['product_tmpl_id', 'barcode', 'default_code'])[0] ?? [];
                        $this->otro->productos()->upsert([self::filaLocal($oid, $nombre, $p, $it, $categ)], ['id']);
                        $it->update(['otro_product_id' => $oid, 'otro_nombre' => $nombre, 'otro_costo_anterior' => (float) $it->costo_nuevo, 'otro_stock_anterior' => 0, 'otro_stock_aplicado' => false]);
                    }
                } catch (\Throwable $e) {
                    $avisos[] = "No se pudo crear en el {$this->otro->nombre()}: " . $e->getMessage();
                }
            }

            $it->update([
                'crear'          => false,
                // el código ya quedó guardado al crearlo
                'guardar_codigo' => $it->match === 'creado' ? null : $it->guardar_codigo,
            ]);
            if ($avisos) $avisosPorItem[$it->id] = implode(' · ', $avisos);
        }
        return [$errores, $avisosPorItem];
    }

    /**
     * Crea el producto (con stock) y guarda el código del renglón. Devuelve el id de product.product.
     * @param OdooProducto|null $modelo el mismo producto en el otro Odoo, si ya existe: de ahí salen el código de barras y la referencia
     */
    private function crearEn(OdooClient $c, CargaItem $it, string $nombre, ?int $categId, ?int $partner, float $venta, ?OdooProducto $modelo = null): int
    {
        $guardar = $it->codigo ? ($it->guardar_codigo ?: null) : null;
        // sin contacto del proveedor en ese Odoo, el código va como referencia interna (así igual lo encuentra la próxima)
        if ($guardar === 'proveedor' && !$partner) $guardar = 'referencia';

        $barcode = collect([$it->barcode, $modelo?->barcode])->first(fn ($b) => $b && preg_match('/^\d{8,14}$/', $b));
        $valores = array_filter([
            'name'           => $nombre,
            'standard_price' => (float) $it->costo_nuevo,
            'list_price'     => $venta > 0 ? $venta : null,
            'categ_id'       => $categId,
            'default_code'   => $guardar === 'referencia' ? $it->codigo : ($modelo?->default_code ?: null),
            'barcode'        => $barcode,
        ], fn ($v) => $v !== null) + $c->camposAlmacenable();

        try {
            $id = $c->call('product.product', 'create', [$valores]);
        } catch (\Throwable $e) {
            // el código de barras ya lo tiene otro producto (archivado, por ejemplo): se crea sin él
            if (!isset($valores['barcode'])) throw $e;
            unset($valores['barcode']);
            $id = $c->call('product.product', 'create', [$valores]);
        }
        $id = (int) (is_array($id) ? $id[0] : $id);

        if ($guardar === 'proveedor') {
            $tmpl = $c->read('product.product', [$id], ['product_tmpl_id'])[0]['product_tmpl_id'][0] ?? null;
            $c->call('product.supplierinfo', 'create', [[
                'partner_id' => $partner, 'product_tmpl_id' => $tmpl, 'product_id' => $id,
                'product_code' => $it->codigo, 'price' => (float) $it->costo_nuevo,
            ]]);
        }
        return $id;
    }

    private static function filaLocal(int $id, string $nombre, array $p, CargaItem $it, ?int $categId): array
    {
        return [
            'id'              => $id,
            'nombre'          => mb_substr($nombre, 0, 250),
            'nombre_norm'     => mb_substr(Matcher::normalizar($nombre), 0, 250),
            'barcode'         => ($p['barcode'] ?? false) ?: null,
            'default_code'    => ($p['default_code'] ?? false) ?: null,
            'costo'           => (float) $it->costo_nuevo,
            'precio'          => (float) $it->precio_nuevo,
            'categ_id'        => $categId,
            'product_tmpl_id' => is_array($p['product_tmpl_id'] ?? null) ? $p['product_tmpl_id'][0] : null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ];
    }
}
