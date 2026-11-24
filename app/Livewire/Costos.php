<?php

namespace App\Livewire;

use App\Models\CargaItem;
use App\Models\Categoria;
use App\Models\OdooProducto;
use App\Services\Matcher;
use Livewire\Attributes\Layout;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Qué productos ya tienen el costo actualizado y cuáles faltan.
 * Actualizado = entró en una lista o factura aplicada (no los reajustes, que no tocan el costo), o se marcó a mano.
 */
#[Layout('layouts.app')]
#[Title('Costos')]
class Costos extends Component
{
    use WithPagination;

    public string $estado = 'faltan';   // faltan | actualizados | viejos | todos
    public $dias = 60;                  // "viejos": actualizados hace más de estos días
    public string $busqueda = '';
    public $categoria = '';

    public function updated($prop): void
    {
        if (in_array($prop, ['estado', 'dias', 'busqueda', 'categoria'], true)) $this->resetPage();
    }

    public function marcar(int $id, bool $revisado): void
    {
        OdooProducto::where('id', $id)->update(['costo_revisado_at' => $revisado ? now() : null]);
    }

    /** Productos sin costo cargado en Odoo (costo 0), según el último "Actualizar catálogo"; respeta la categoría elegida. */
    private function sinCosto()
    {
        return OdooProducto::query()
            ->where(fn ($q) => $q->whereNull('costo')->orWhere('costo', '<=', 0))
            ->when($this->categoria !== '' && $this->categoria !== null, fn ($q) => $q->where('categ_id', (int) $this->categoria));
    }

    /** Excel con todos los productos sin costo, para completarlo. */
    public function descargarSinCosto()
    {
        $cats = Categoria::pluck('nombre', 'id');
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Sin costo');
        $hoja->fromArray(['Producto', 'Referencia interna', 'Código de barras', 'Categoría', 'Precio de venta', 'Costo', 'ID Odoo'], null, 'A1');

        $fila = 2;
        foreach ($this->sinCosto()->orderBy('nombre')->cursor() as $p) {
            $hoja->fromArray([$p->nombre, $p->default_code, null, $cats[$p->categ_id] ?? '', (float) $p->precio, null, $p->id], null, "A$fila");
            // como texto: si no, Excel muestra "7,79E+12" y le saca los ceros de adelante
            if ($p->barcode) $hoja->setCellValueExplicit("C$fila", $p->barcode, DataType::TYPE_STRING);
            $fila++;
        }

        $hoja->getStyle('A1:G1')->getFont()->setBold(true);
        $hoja->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E2E8F0');
        $hoja->getStyle("E2:E$fila")->getNumberFormat()->setFormatCode('#,##0.00');
        $hoja->freezePane('A2');
        $hoja->setAutoFilter('A1:G' . max(1, $fila - 1));
        foreach (['A' => 60, 'B' => 20, 'C' => 18, 'D' => 35, 'E' => 15, 'F' => 12, 'G' => 10] as $col => $ancho) {
            $hoja->getColumnDimension($col)->setWidth($ancho);
        }

        $nombre = 'productos-sin-costo-' . now()->format('Y-m-d') . '.xlsx';
        return response()->streamDownload(function () use ($libro) {
            (new Xlsx($libro))->save('php://output');
        }, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Productos con la fecha de su último costo (lista/factura aplicada o marcado a mano). */
    private function base()
    {
        $porCarga = CargaItem::query()
            ->join('cargas', 'cargas.id', '=', 'carga_items.carga_id')
            ->where('carga_items.aplicado', true)->whereNotNull('cargas.proveedor_id')
            ->groupBy('carga_items.odoo_product_id')
            ->selectRaw('carga_items.odoo_product_id as pid, MAX(cargas.aplicada_at) as fecha');

        return OdooProducto::query()
            ->leftJoinSub($porCarga, 'u', 'u.pid', '=', 'odoo_productos.id')
            ->select('odoo_productos.*', 'u.fecha as fecha_carga')
            ->selectRaw(self::FECHA . ' as fecha_costo')
            ->when($this->categoria !== '' && $this->categoria !== null, fn ($q) => $q->where('categ_id', (int) $this->categoria));
    }

    /** la más nueva entre la carga y la marca a mano (en SQLite MAX(a, NULL) da NULL) */
    private const FECHA = 'CASE WHEN u.fecha IS NULL THEN odoo_productos.costo_revisado_at
        WHEN odoo_productos.costo_revisado_at IS NULL OR u.fecha > odoo_productos.costo_revisado_at THEN u.fecha
        ELSE odoo_productos.costo_revisado_at END';

    private function filtrar($q, string $estado)
    {
        $limite = now()->subDays(max(1, (int) $this->dias))->toDateTimeString();
        return match ($estado) {
            'faltan'      => $q->whereRaw(self::FECHA . ' IS NULL'),
            'actualizados'=> $q->whereRaw(self::FECHA . ' IS NOT NULL'),
            'viejos'      => $q->whereRaw(self::FECHA . ' < ?', [$limite]),
            default       => $q,
        };
    }

    public function render()
    {
        $q = $this->filtrar($this->base(), $this->estado);
        $txt = trim($this->busqueda);
        if (mb_strlen($txt) >= 2) {
            $q->where(function ($w) use ($txt) {
                $w->where(function ($n) use ($txt) {
                    foreach (Matcher::tokens(Matcher::normalizar($txt)) as $t) $n->where('nombre_norm', 'like', '%' . $t . '%');
                })->orWhere('barcode', $txt)->orWhere('default_code', 'like', $txt . '%');
            });
        }
        $productos = $q->orderByRaw($this->estado === 'faltan' ? 'odoo_productos.nombre' : 'fecha_costo DESC, odoo_productos.nombre')->paginate(50);

        // de dónde vino el último costo de cada producto de esta página
        $origen = CargaItem::query()
            ->join('cargas', 'cargas.id', '=', 'carga_items.carga_id')
            ->leftJoin('proveedores', 'proveedores.id', '=', 'cargas.proveedor_id')
            ->where('carga_items.aplicado', true)->whereNotNull('cargas.proveedor_id')
            ->whereIn('carga_items.odoo_product_id', $productos->pluck('id'))
            ->orderBy('cargas.aplicada_at')
            ->get(['carga_items.odoo_product_id', 'cargas.id as carga_id', 'cargas.tipo', 'proveedores.nombre as proveedor'])
            ->keyBy('odoo_product_id');   // queda la más nueva

        $conteo = [];
        foreach (['todos', 'actualizados', 'faltan', 'viejos'] as $e) {
            $conteo[$e] = $this->filtrar($this->base(), $e)->count();
        }

        return view('livewire.costos', [
            'productos'  => $productos,
            'origen'     => $origen,
            'conteo'     => $conteo,
            'sinCosto'   => $this->sinCosto()->count(),
            'categorias' => Categoria::whereIn('id', OdooProducto::select('categ_id')->distinct())->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }
}
