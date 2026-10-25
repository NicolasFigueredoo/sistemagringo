<?php

namespace App\Livewire;

use App\Models\Carga;
use App\Models\CargaItem;
use App\Models\Categoria;
use App\Models\OdooProducto;
use App\Services\Matcher;
use App\Services\OdooClient;
use App\Services\Precios;
use App\Services\Procesador;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Revisar precios')]
class RevisarCarga extends Component
{
    use WithPagination;

    private const VERDES = ['barcode', 'mapeo', 'codigo', 'manual', 'categoria'];

    public Carga $carga;
    public string $filtro = 'todos';     // todos (sin los grises) | verde | amarillo | naranja | gris | rojo
    public $categoriaFiltro = '';         // id de categoría para filtrar la tabla

    // bloque "Por categoría": [categoria_id => valor]
    public array $catMargen = [];
    public array $catDescuento = [];
    public array $catGuardar = [];
    public $margenMasivo = '';
    public bool $sumarIva = true;
    public $redondeo = 0;
    public string $iva = 'tal_cual';    // factura: tal_cual | sacar | agregar

    // buscador manual de producto
    public ?int $buscandoItem = null;
    public string $busqueda = '';

    public ?string $mensaje = null;
    public ?string $error = null;

    public function mount(Carga $carga): void
    {
        $this->carga = $carga;
        $this->sumarIva = $carga->sumar_iva;
        $this->redondeo = $carga->redondeo;
        $this->iva = $carga->agregar_iva ? 'agregar' : ($carga->iva_incluido ? 'sacar' : 'tal_cual');
        $this->margenMasivo = (string) (($carga->proveedor?->margen ?? $carga->categoria?->margen ?? 0) + 0);
        $this->mensaje = session('aviso');
    }

    private function proc(): Procesador
    {
        return new Procesador(OdooClient::desdeConfig());
    }

    private function item(int $id): CargaItem
    {
        return $this->carga->items()->findOrFail($id);
    }

    private function editable(): bool
    {
        return $this->carga->estado === 'revision';
    }

    public function updatedFiltro(): void { $this->resetPage(); }
    public function updatedCategoriaFiltro(): void { $this->resetPage(); }

    public function aplicarCategoria(int $catId): void
    {
        if (!$this->editable()) return;
        $margen = ($this->catMargen[$catId] ?? '') === '' ? null : Precios::numero($this->catMargen[$catId]);
        $desc = ($this->catDescuento[$catId] ?? '') === '' ? null : Precios::numero($this->catDescuento[$catId]);
        if ($margen === null && $desc === null) { $this->error = 'Escribí la ganancia o el descuento para esa categoría.'; return; }

        $n = $this->proc()->aplicarCategoria($this->carga, $catId, $margen, $desc, (bool) ($this->catGuardar[$catId] ?? true));
        $nombre = Categoria::find($catId)?->nombre;
        $this->mensaje = "Se aplicó a $n productos de \"$nombre\".";
        $this->error = null;
        // mostrar en la tabla los productos de esa categoría
        $this->categoriaFiltro = (string) $catId;
        $this->filtro = 'todos';
        $this->resetPage();
    }

    public function updatedSumarIva(): void
    {
        if (!$this->editable()) return;
        $this->carga->update(['sumar_iva' => $this->sumarIva]);
        $this->proc()->recalcular($this->carga->fresh());
    }

    /** Factura: los precios vienen con IVA (se saca) o sin IVA (se agrega al costo). */
    public function updatedIva(): void
    {
        if (!$this->editable() || !$this->carga->es_factura) return;
        $this->carga->update(['iva_incluido' => $this->iva === 'sacar', 'agregar_iva' => $this->iva === 'agregar']);
        $this->proc()->aplicarReglas($this->carga->fresh());
    }

    public function updatedRedondeo(): void
    {
        if (!$this->editable()) return;
        $this->carga->update(['redondeo' => (int) $this->redondeo]);
        $this->proc()->recalcular($this->carga->fresh());
    }

    public function aplicarMargenMasivo(): void
    {
        if (!$this->editable()) return;
        $m = Precios::numero($this->margenMasivo);
        if ($m === null) { $this->error = 'Escribí un porcentaje válido.'; return; }
        $this->proc()->recalcular($this->carga, $m);
        $this->mensaje = "Se aplicó {$m}% de ganancia a todos los productos.";
        $this->error = null;
    }

    public function cambiarMargen(int $id, $valor): void
    {
        if (!$this->editable()) return;
        $v = Precios::numero($valor);
        if ($v !== null) $this->proc()->cambiarMargen($this->item($id), $v);
    }

    public function cambiarPrecio(int $id, $valor): void
    {
        if (!$this->editable()) return;
        $v = Precios::numero($valor);
        if ($v !== null && $v > 0) $this->proc()->cambiarPrecio($this->item($id), $v);
    }

    public function cambiarCantidad(int $id, $valor): void
    {
        if (!$this->editable()) return;
        $v = trim((string) $valor) === '' ? null : Precios::numero($valor);
        $this->item($id)->update(['cantidad' => $v !== null && $v > 0 ? $v : null]);
    }

    public function toggle(int $id): void
    {
        if (!$this->editable()) return;
        $it = $this->item($id);
        if (!$it->odoo_product_id) return;
        $it->update(['seleccionado' => !$it->seleccionado]);
    }

    public function marcarTodos(bool $valor): void
    {
        if (!$this->editable()) return;
        $q = $this->filtrar($this->carga->items()->whereNotNull('odoo_product_id'));
        $q->update(['seleccionado' => $valor]);
    }

    public function elegir(int $id, int $productoId): void
    {
        if (!$this->editable()) return;
        $this->proc()->elegir($this->item($id), $productoId);
        $this->buscandoItem = null;
        $this->busqueda = '';
    }

    public function quitar(int $id): void
    {
        if (!$this->editable()) return;
        $this->item($id)->update(['odoo_product_id' => null, 'odoo_nombre' => null, 'match' => 'ninguno', 'seleccionado' => false, 'costo_anterior' => null, 'precio_anterior' => null]);
    }

    public function abrirBuscador(int $id): void
    {
        $this->buscandoItem = $id;
        $this->busqueda = (string) $this->item($id)->nombre;
    }

    public function cerrarBuscador(): void
    {
        $this->buscandoItem = null;
        $this->busqueda = '';
    }

    public function aplicar(): void
    {
        if (!$this->editable()) return;
        try {
            [$ok, $errores] = $this->proc()->aplicar($this->carga);
            $this->carga->refresh();
            $this->mensaje = "Listo: se actualizaron $ok productos en Odoo." . ($errores ? " $errores con error (marcados en rojo)." : '');
            $this->error = null;
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'No se pudo actualizar Odoo: ' . $e->getMessage();
        }
    }

    private function filtrar($q)
    {
        if ($this->categoriaFiltro !== '' && $this->categoriaFiltro !== null) {
            $q->where('categoria_id', (int) $this->categoriaFiltro);
        }
        return match ($this->filtro) {
            'verde'    => $q->whereIn('match', self::VERDES),
            'amarillo' => $q->where('match', 'nombre'),
            'naranja'  => $q->where('match', 'parecido'),
            'gris'     => $q->where('match', 'ninguno'),
            'rojo'     => $q->whereNotNull('costo_anterior')->where('costo_anterior', '>', 0)
                            ->whereRaw('ABS(costo_nuevo / costo_anterior - 1) > 0.30'),
            default    => $q->where('match', '!=', 'ninguno'),   // los grises son productos que no vende
        };
    }

    private function resultadosBusqueda(): array
    {
        $txt = trim($this->busqueda);
        if (mb_strlen($txt) < 2) return [];

        $q = OdooProducto::query();
        if (preg_match('/^\d{6,14}$/', $txt)) {
            $q->where('barcode', $txt)->orWhere('default_code', $txt);
        } else {
            // por nombre, o por la referencia interna tal cual ("LS-9")
            $q->where(function ($w) use ($txt) {
                foreach (Matcher::tokens(Matcher::normalizar($txt)) as $t) {
                    $w->where('nombre_norm', 'like', '%' . $t . '%');
                }
            })->orWhere('default_code', $txt);
        }
        return $q->orderBy('nombre')->limit(15)->get(['id', 'nombre', 'barcode', 'costo', 'precio'])->toArray();
    }

    public function render()
    {
        $base = $this->carga->items();
        $conteo = [
            'todos'    => (clone $base)->where('match', '!=', 'ninguno')->count(),
            'verde'    => (clone $base)->whereIn('match', self::VERDES)->count(),
            'amarillo' => (clone $base)->where('match', 'nombre')->count(),
            'naranja'  => (clone $base)->where('match', 'parecido')->count(),
            'gris'     => (clone $base)->where('match', 'ninguno')->count(),
            'rojo'     => $this->filtrarCon('rojo')->count(),
        ];

        // categorías presentes en esta carga (solo productos encontrados)
        $cats = $this->carga->items()->whereNotNull('categoria_id')
            ->selectRaw('categoria_id, COUNT(*) as cant')->groupBy('categoria_id')->pluck('cant', 'categoria_id');
        $categorias = Categoria::whereIn('id', $cats->keys())->orderBy('nombre')->get()
            ->each(function ($c) use ($cats) {
                $c->cant = $cats[$c->id];
                // valores por defecto en los inputs: lo guardado en la categoría
                $this->catMargen[$c->id] ??= $c->margen !== null ? rtrim(rtrim((string) $c->margen, '0'), '.') : '';
                $this->catDescuento[$c->id] ??= $c->descuento !== null ? rtrim(rtrim((string) $c->descuento, '0'), '.') : '';
                $this->catGuardar[$c->id] ??= true;
            });

        return view('livewire.revisar-carga', [
            'categorias'   => $categorias,
            'items'        => $this->filtrar($this->carga->items())->orderBy('fila')->paginate(50),
            'conteo'       => $conteo,
            'seleccionados'=> (clone $base)->where('seleccionado', true)->whereNotNull('odoo_product_id')->count(),
            'conStock'     => (clone $base)->where('seleccionado', true)->whereNotNull('odoo_product_id')->where('cantidad', '>', 0)->count(),
            'resultados'   => $this->buscandoItem ? $this->resultadosBusqueda() : [],
            'editable'     => $this->editable(),
        ]);
    }

    private function filtrarCon(string $f)
    {
        [$orig, $origCat] = [$this->filtro, $this->categoriaFiltro];
        [$this->filtro, $this->categoriaFiltro] = [$f, ''];
        $q = $this->filtrar($this->carga->items());
        [$this->filtro, $this->categoriaFiltro] = [$orig, $origCat];
        return $q;
    }
}
