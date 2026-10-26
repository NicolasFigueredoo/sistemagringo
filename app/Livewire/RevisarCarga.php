<?php

namespace App\Livewire;

use App\Jobs\AplicarCarga;
use App\Models\Carga;
use App\Models\CargaItem;
use App\Models\Categoria;
use App\Models\CodigoProveedor;
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
    /** no se encontraron por código de barras ni referencia: hay que revisarlos a mano */
    private const PENDIENTES = ['nombre', 'parecido', 'ninguno'];

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
    public string $guardarCodigo = '';   // '' | proveedor | referencia
    public bool $recorriendo = false;    // al asociar o saltear pasa al siguiente sin encontrar

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
        $guardar = $this->buscandoItem === $id ? ($this->guardarCodigo ?: null) : null;
        $this->proc()->elegir($this->item($id), $productoId, $guardar);
        $this->recorriendo ? $this->siguiente() : $this->cerrarBuscador();
    }

    /** "No hacer nada" con este renglón: no se actualiza y la próxima lista ya viene ignorado. */
    public function ignorar(int $id): void
    {
        if (!$this->editable()) return;
        $this->proc()->ignorar($this->item($id));
        if ($this->buscandoItem === $id) {
            $this->recorriendo ? $this->siguiente() : $this->cerrarBuscador();
        }
    }

    /** Abre el buscador con el primer renglón que no se encontró por código y va pasando de a uno. */
    public function recorrer(): void
    {
        $this->recorriendo = true;
        $this->buscandoItem = null;
        $this->siguiente();
    }

    /** Siguiente renglón sin encontrar (sin cambiar el actual). */
    public function siguiente(): void
    {
        $fila = $this->buscandoItem ? $this->item($this->buscandoItem)->fila : 0;
        $q = fn () => $this->carga->items()->whereIn('match', self::PENDIENTES)->whereNull('odoo_product_id')->orderBy('fila');
        $sig = $q()->where('fila', '>', $fila)->first() ?? $q()->where('id', '!=', $this->buscandoItem ?? 0)->first();
        if (!$sig) {
            $this->cerrarBuscador();
            $this->mensaje = 'No quedan productos sin encontrar.';
            return;
        }
        $this->abrirBuscador($sig->id);
    }

    public function quitar(int $id): void
    {
        if (!$this->editable()) return;
        $this->item($id)->update(['odoo_product_id' => null, 'odoo_nombre' => null, 'match' => 'ninguno', 'seleccionado' => false, 'guardar_codigo' => null, 'costo_anterior' => null, 'precio_anterior' => null]);
    }

    public function abrirBuscador(int $id): void
    {
        $it = $this->item($id);
        $this->buscandoItem = $id;
        $this->busqueda = (string) $it->nombre;
        // por defecto se guarda el código del proveedor, así la próxima lista lo encuentra sola
        $this->guardarCodigo = $it->guardar_codigo ?? ($it->codigo && $this->carga->proveedor?->odoo_partner_id ? 'proveedor' : '');
    }

    public function cerrarBuscador(): void
    {
        $this->buscandoItem = null;
        $this->busqueda = '';
        $this->guardarCodigo = '';
        $this->recorriendo = false;
    }

    /** Arranca la actualización en segundo plano; la pantalla muestra el avance con progreso(). */
    public function aplicar(): void
    {
        if (!$this->editable()) return;
        $this->proc()->iniciarAplicacion($this->carga);
        AplicarCarga::dispatch($this->carga->id);
        $this->carga->refresh();
        $this->mensaje = $this->error = null;
    }

    /**
     * Lo llama la pantalla cada pocos segundos mientras se actualiza.
     * Si el proceso en segundo plano no avanza (no está corriendo o se cortó), sigue la pantalla misma de a una tanda.
     */
    public function progreso(): void
    {
        $this->carga->refresh();
        if ($this->carga->estado === 'aplicando' && $this->carga->updated_at->lt(now()->subSeconds(20))) {
            try {
                AplicarCarga::tanda($this->proc(), $this->carga);
            } catch (\Throwable $e) {
                report($e);
                $this->error = 'Se cortó la conexión con Odoo, se reintenta solo: ' . $e->getMessage();
                $this->carga->touch();   // esperar otros 20 segundos antes de reintentar
            }
            $this->carga->refresh();
        }
        if ($this->carga->estado === 'aplicada') {
            $ok = $this->carga->progreso_hecho - $this->carga->progreso_errores;
            $err = $this->carga->progreso_errores;
            $this->mensaje = "Listo: se actualizaron $ok productos en Odoo." . ($err ? " $err con error (marcados en rojo, filtro \"Todos\")." : '');
            $this->error = null;
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
            'gris'     => $q->whereIn('match', ['ninguno', 'ignorado']),
            'rojo'     => $q->whereNotNull('costo_anterior')->where('costo_anterior', '>', 0)
                            ->whereRaw('ABS(costo_nuevo / costo_anterior - 1) > 0.30'),
            default    => $q->whereNotIn('match', ['ninguno', 'ignorado']),   // los grises son productos que no vende
        };
    }

    private function resultadosBusqueda(): array
    {
        $txt = trim($this->busqueda);
        if (mb_strlen($txt) < 2) return [];

        // por nombre, código de barras, referencia interna o código de cualquier proveedor
        $q = OdooProducto::query()->where(function ($w) use ($txt) {
            if ($tokens = Matcher::tokens(Matcher::normalizar($txt))) {
                $w->where(function ($n) use ($tokens) {
                    foreach ($tokens as $t) $n->where('nombre_norm', 'like', '%' . $t . '%');
                });
            }
            $w->orWhere('barcode', $txt)->orWhere('default_code', 'like', $txt . '%');
            if ($k = Matcher::codigoClave($txt)) {
                $w->orWhereIn('id', CodigoProveedor::where('codigo', $k)->select('product_id'));
            }
        });
        return $q->orderBy('nombre')->limit(20)->get(['id', 'nombre', 'barcode', 'default_code', 'costo', 'precio'])->toArray();
    }

    public function render()
    {
        $base = $this->carga->items();
        $conteo = [
            'todos'    => (clone $base)->whereNotIn('match', ['ninguno', 'ignorado'])->count(),
            'verde'    => (clone $base)->whereIn('match', self::VERDES)->count(),
            'amarillo' => (clone $base)->where('match', 'nombre')->count(),
            'naranja'  => (clone $base)->where('match', 'parecido')->count(),
            'gris'     => (clone $base)->whereIn('match', ['ninguno', 'ignorado'])->count(),
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
            'buscando'     => $this->buscandoItem ? $this->item($this->buscandoItem) : null,
            'pendientes'   => $this->editable() ? (clone $base)->whereIn('match', self::PENDIENTES)->whereNull('odoo_product_id')->count() : 0,
            'conPartner'   => (bool) $this->carga->proveedor?->odoo_partner_id,
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
