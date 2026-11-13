<?php

namespace App\Services;

use App\Models\Carga;
use App\Models\CargaItem;
use App\Models\Categoria;
use App\Models\CodigoProveedor;
use App\Models\OdooProducto;
use App\Models\Proveedor;
use App\Support\Instancia;
use Illuminate\Support\Collection;

/**
 * El "otro" Odoo de una carga: el viejo si se está trabajando en el nuevo, y al revés.
 * Usa la base local del otro (catálogo, categorías, proveedores) y su propia conexión a Odoo.
 *
 * Facturas por foto: cada renglón se busca también acá, para actualizar el costo en los dos
 * y sumar el stock en el viejo. Productos nuevos: se crean en los dos.
 */
class OtroOdoo
{
    private ?OdooClient $cliente = null;
    private ?Matcher $matcher = null;
    private array $partners = [];

    public function __construct(private string $conexion, private array $config) {}

    /** null si el otro Odoo no está configurado. */
    public static function crear(): ?self
    {
        return Instancia::hayOtro() ? new self(Instancia::conexionOtra(), Instancia::configOtro()) : null;
    }

    public function nombre(): string
    {
        return Instancia::nombreOtro();
    }

    public function cliente(): OdooClient
    {
        return $this->cliente ??= OdooClient::desdeArray($this->config);
    }

    public function productos()
    {
        return OdooProducto::on($this->conexion);
    }

    /** Contacto de Odoo del proveedor en el otro Odoo (los proveedores se relacionan por nombre). */
    public function partner(?Proveedor $prov): ?int
    {
        if (!$prov) return null;
        $clave = mb_strtolower(trim($prov->nombre));
        if (!array_key_exists($clave, $this->partners)) {
            $id = Proveedor::on($this->conexion)->whereRaw('LOWER(TRIM(nombre)) = ?', [$clave])->value('odoo_partner_id');
            $this->partners[$clave] = $id ? (int) $id : null;
        }
        return $this->partners[$clave];
    }

    /** Categoría del otro Odoo con el mismo nombre completo ("Ferretería / Tornillos"). */
    public function categoria(?int $categIdPropia): ?int
    {
        $nombre = $categIdPropia ? Categoria::find($categIdPropia)?->nombre : null;
        if (!$nombre) return null;
        $id = Categoria::on($this->conexion)->where('nombre', $nombre)->value('id');
        return $id ? (int) $id : null;
    }

    private function matcher(?Proveedor $prov): Matcher
    {
        if ($this->matcher) return $this->matcher;
        $partner = $this->partner($prov);
        $codigos = CodigoProveedor::on($this->conexion)->select('codigo', 'product_id')
            ->when($partner, fn ($q) => $q->where('partner_id', $partner))
            ->toBase()->get();
        return $this->matcher = new Matcher(
            $this->productos()->select('id', 'nombre', 'barcode', 'default_code')->toBase()->cursor(),
            [],
            $codigos,
            (bool) $partner
        );
    }

    /**
     * El producto del otro Odoo que corresponde a este renglón: por los datos de la factura y, si no,
     * por el código de barras / referencia / nombre del producto elegido en este Odoo. Solo coincidencias seguras.
     */
    public function buscar(CargaItem $it, ?Proveedor $prov, ?OdooProducto $propio = null): ?array
    {
        $m = $this->matcher($prov);
        $intentos = [[$it->barcode, $it->codigo, $it->crear_nombre ?: $it->nombre]];
        if ($propio) $intentos[] = [$propio->barcode, $propio->default_code, $propio->nombre];
        foreach ($intentos as [$barcode, $codigo, $nombre]) {
            $r = $m->buscar($barcode, $codigo, $nombre);
            $seguro = in_array($r['match'], ['barcode', 'codigo'], true)
                || ($r['match'] === 'nombre' && Matcher::normalizar((string) $nombre) === Matcher::normalizar((string) $r['odoo_nombre']));
            if ($seguro && $r['odoo_product_id']) return ['id' => $r['odoo_product_id'], 'nombre' => $r['odoo_nombre']];
        }
        return null;
    }

    /** Busca en el otro Odoo cada renglón de la factura y trae su costo (y stock, si se suma ahí). */
    public function vincular(Carga $carga, Collection $items, bool $conStock): void
    {
        $propios = OdooProducto::whereIn('id', $items->pluck('odoo_product_id')->filter()->unique())->get()->keyBy('id');
        foreach ($items as $it) {
            if ($it->otro_aplicado) continue;
            $r = $this->buscar($it, $carga->proveedor, $propios->get($it->odoo_product_id));
            $it->update(['otro_product_id' => $r['id'] ?? null, 'otro_nombre' => $r['nombre'] ?? null, 'otro_costo_anterior' => null]);
        }
        $this->refrescar($items, $conStock);
    }

    /** Costo (y stock) actual en el otro Odoo, para comparar y para deshacer. */
    public function refrescar(Collection $items, bool $conStock): void
    {
        $con = $items->filter(fn ($it) => $it->otro_product_id);
        foreach ($con->chunk(2000) as $chunk) {
            $actual = collect($this->cliente()->read('product.product', $chunk->pluck('otro_product_id')->unique()->values()->all(), ['standard_price', 'qty_available']))->keyBy('id');
            foreach ($chunk as $it) {
                if (!$a = $actual->get($it->otro_product_id)) continue;
                $datos = ['otro_costo_anterior' => $a['standard_price']];
                if ($conStock) $datos['stock_anterior'] = $a['qty_available'] ?? null;
                $it->update($datos);
            }
        }
        // si el stock va al otro Odoo y el producto no está ahí, no hay stock para mostrar
        if ($conStock) {
            $items->filter(fn ($it) => !$it->otro_product_id && $it->stock_anterior !== null)->each->update(['stock_anterior' => null]);
        }
    }

    /**
     * Escribe el costo en el otro Odoo y, si corresponde, suma el stock ahí.
     * Los renglones quedan marcados (otro_aplicado, stock_aplicado). Devuelve [item_id => aviso].
     */
    public function aplicar(Collection $items, bool $conStock): array
    {
        $avisos = [];
        $pend = $items->filter(fn ($it) => $it->otro_product_id && !$it->otro_aplicado)->values();
        if ($conStock) {
            foreach ($items as $it) {
                if (!$it->otro_product_id && (float) $it->cantidad > 0 && !$it->stock_aplicado) {
                    $avisos[$it->id] = "No está en el {$this->nombre()}: no se sumó el stock";
                }
            }
        }
        if ($pend->isEmpty()) return $avisos;

        try {
            $this->refrescar($pend, $conStock);
        } catch (\Throwable $e) {
            foreach ($pend as $it) $avisos[$it->id] = "{$this->nombre()}: " . $e->getMessage();
            return $avisos;
        }

        $escribir = $pend->reject(fn ($it) => $it->otro_costo_anterior !== null && abs((float) $it->otro_costo_anterior - (float) $it->costo_nuevo) < 0.005);
        $fallas = $this->cliente()->writeMany('product.product', $escribir->mapWithKeys(fn ($it) => [
            $it->id => [[(int) $it->otro_product_id], ['standard_price' => (float) $it->costo_nuevo]],
        ])->all());

        $conCant = $pend->filter(fn ($it) => $conStock && !isset($fallas[$it->id]) && (float) $it->cantidad > 0 && !$it->stock_aplicado);
        $erroresStock = $conCant->isEmpty() ? [] : $this->cliente()->sumarStock(
            $conCant->groupBy('otro_product_id')->map(fn ($g) => (float) $g->sum('cantidad'))->all()
        );

        foreach ($pend as $it) {
            if (isset($fallas[$it->id])) {
                $avisos[$it->id] = "{$this->nombre()}: " . $fallas[$it->id];
                continue;
            }
            $errStock = $conCant->contains('id', $it->id) ? ($erroresStock[$it->otro_product_id] ?? null) : null;
            $it->update([
                'otro_aplicado'  => true,
                'stock_aplicado' => $it->stock_aplicado || ($conCant->contains('id', $it->id) && !$errStock),
            ]);
            $this->productos()->where('id', $it->otro_product_id)->update(['costo' => (float) $it->costo_nuevo]);
            if ($errStock) $avisos[$it->id] = "{$this->nombre()}: $errStock";
        }
        return $avisos;
    }

    /** Vuelve el costo anterior en el otro Odoo y, si el stock se sumó ahí, lo resta. Devuelve [item_id => error]. */
    public function deshacer(Carga $carga, bool $conStock): array
    {
        $errores = [];
        $items = $carga->items()->where('otro_aplicado', true)->whereNotNull('otro_costo_anterior')->get();
        $fallas = $this->cliente()->writeMany('product.product', $items->mapWithKeys(fn ($it) => [
            $it->id => [[(int) $it->otro_product_id], ['standard_price' => (float) $it->otro_costo_anterior]],
        ])->all());
        foreach ($items as $it) {
            if (isset($fallas[$it->id])) { $errores[$it->id] = $fallas[$it->id]; continue; }
            $it->update(['otro_aplicado' => false]);
            $this->productos()->where('id', $it->otro_product_id)->update(['costo' => (float) $it->otro_costo_anterior]);
        }

        if ($conStock) {
            $conCant = $carga->items()->where('stock_aplicado', true)->whereNotNull('otro_product_id')->get();
            $erroresStock = $conCant->isEmpty() ? [] : $this->cliente()->sumarStock(
                $conCant->groupBy('otro_product_id')->map(fn ($g) => -(float) $g->sum('cantidad'))->all()
            );
            foreach ($conCant as $it) {
                if (isset($erroresStock[$it->otro_product_id])) { $errores[$it->id] = $erroresStock[$it->otro_product_id]; continue; }
                $it->update(['stock_aplicado' => false]);
            }
        }
        return $errores;
    }
}
