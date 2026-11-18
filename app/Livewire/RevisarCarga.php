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
use App\Support\Instancia;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Revisar precios')]
class RevisarCarga extends Component
{
    use WithPagination;

    private const VERDES = ['barcode', 'mapeo', 'codigo', 'manual', 'categoria', 'creado'];
    /** no se encontraron por código de barras ni referencia: hay que revisarlos a mano */
    private const PENDIENTES = ['nombre', 'parecido', 'ninguno'];

    public Carga $carga;
    public string $filtro = 'todos';     // todos (sin los grises) | verde | amarillo | naranja | crear | falta | gris | rojo
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

    // producto nuevo: se crea al tocar "Actualizar en Odoo", en los dos Odoo
    public ?int $creandoItem = null;
    public string $crearNombre = '';
    public $crearCategoria = '';
    public string $crearVenta = '';

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
        $desc = Precios::descuento($this->catDescuento[$catId] ?? '');   // acepta "50+13"
        if ($margen === null && $desc === null) { $this->error = 'Escribí la ganancia o el descuento para esa categoría.'; return; }

        $n = $this->proc()->aplicarCategoria($this->carga, $catId, $margen, $desc, (bool) ($this->catGuardar[$catId] ?? true), Precios::textoDescuento($this->catDescuento[$catId] ?? ''));
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

    public function cambiarCosto(int $id, $valor): void
    {
        if (!$this->editable()) return;
        $v = Precios::numero($valor);
        if ($v !== null && $v > 0) $this->proc()->cambiarCosto($this->item($id), $v);
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
        if (!$it->odoo_product_id && !$it->crear) return;
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
        $this->item($id)->update(['odoo_product_id' => null, 'odoo_nombre' => null, 'match' => 'ninguno', 'seleccionado' => false, 'guardar_codigo' => null, 'costo_anterior' => null, 'precio_anterior' => null, 'stock_anterior' => null,
            'crear' => false, 'crear_nombre' => null, 'crear_categ_id' => null]);
        $this->proc()->vincularOtro($this->carga, [$id]);
    }

    /** Abre la ficha para crear el producto (nombre, categoría, venta y dónde guardar el código). */
    public function abrirCrear(int $id): void
    {
        if (!$this->editable()) return;
        $it = $this->item($id);
        $this->buscandoItem = null;
        $this->creandoItem = $id;
        // si ya existe en el otro Odoo, se propone crearlo igual: mismo nombre, categoría y venta
        $delOtro = !$it->crear && $it->otro_product_id ? $this->proc()->otro()?->producto((int) $it->otro_product_id) : null;
        $this->crearNombre = (string) ($it->crear_nombre ?: $delOtro?->nombre ?: $it->nombre);
        $this->crearCategoria = (string) ($it->crear ? $it->crear_categ_id : $this->proc()->categoriaSugerida($it));
        $this->guardarCodigo = $it->guardar_codigo ?? ($it->codigo ? ($this->carga->proveedor?->odoo_partner_id ? 'proveedor' : 'referencia') : '');
        $this->crearVenta = match (true) {
            $it->crear                    => number_format((float) $it->precio_nuevo, 2, ',', '.'),
            (float) $delOtro?->precio > 0 => number_format((float) $delOtro->precio, 2, ',', '.'),
            default                       => $this->ventaSugerida($it),
        };
    }

    /** Al cambiar la categoría, la venta se recalcula con su ganancia (o la del proveedor). */
    public function updatedCrearCategoria(): void
    {
        if ($this->creandoItem) $this->crearVenta = $this->ventaSugerida($this->item($this->creandoItem));
    }

    private function ventaSugerida(CargaItem $it): string
    {
        $cat = $this->crearCategoria !== '' ? Categoria::find((int) $this->crearCategoria) : null;
        $margen = $cat?->margen !== null ? (float) $cat->margen : (float) ($this->carga->proveedor?->margen ?? $it->margen);
        $venta = Precios::venta((float) $it->costo_nuevo, $margen, $this->carga->sumar_iva, (int) $this->carga->redondeo);
        return number_format($venta, 2, ',', '.');
    }

    public function guardarCrear(): void
    {
        if (!$this->editable() || !$this->creandoItem) return;
        if (trim($this->crearNombre) === '') { $this->error = 'Escribí el nombre del producto nuevo.'; return; }
        $it = $this->item($this->creandoItem);
        $this->proc()->marcarCrear($it, $this->crearNombre, $this->crearCategoria !== '' ? (int) $this->crearCategoria : null,
            Precios::numero($this->crearVenta), $this->guardarCodigo);
        $this->error = null;
        $recorriendo = $this->recorriendo;
        $this->cerrarCrear();
        if ($recorriendo) { $this->recorriendo = true; $this->buscandoItem = $it->id; $this->siguiente(); }
    }

    /** Un toque: se crea con el nombre de la factura/lista y la categoría sugerida. */
    public function crearRapido(int $id): void
    {
        if (!$this->editable()) return;
        $it = $this->item($id);
        $this->proc()->marcarCrear($it, null, $it->odoo_product_id ? null : $this->proc()->categoriaSugerida($it));
    }

    public function cancelarCrear(int $id): void
    {
        if (!$this->editable()) return;
        $this->proc()->cancelarCrear($this->item($id));
    }

    /** Marca para crear todos los que no se encontraron (y, en facturas, los que faltan en el otro Odoo). */
    public function crearTodos(): void
    {
        if (!$this->editable()) return;
        $proc = $this->proc();
        $n = 0;
        foreach ($this->carga->items()->whereIn('match', ['ninguno', 'parecido'])->whereNull('odoo_product_id')->where('crear', false)->get() as $it) {
            $proc->marcarCrear($it, null, $proc->categoriaSugerida($it));
            $n++;
        }
        foreach ($this->faltanEnOtro()->get() as $it) {
            $proc->marcarCrear($it);
            $n++;
        }
        $this->mensaje = "Se van a crear $n productos al tocar \"Actualizar en Odoo\". Podés cambiar el nombre o la categoría de cada uno con \"Editar\".";
        $this->filtro = 'crear';
        $this->resetPage();
    }

    /** Factura: crea en el otro Odoo los que solo están en este (con los datos de este). */
    public function crearEnOtro(): void
    {
        if (!$this->editable()) return;
        $n = 0;
        foreach ($this->faltanEnOtro()->get() as $it) { $this->proc()->marcarCrear($it); $n++; }
        $this->mensaje = "Se van a crear $n productos en el " . $this->proc()->otro()?->nombre() . ' al tocar "Actualizar en Odoo".';
        $this->filtro = 'crear';
        $this->resetPage();
    }

    /** Factura: crea en este Odoo los que solo están en el otro (con su nombre, categoría, venta y códigos). */
    public function crearEnEste(): void
    {
        if (!$this->editable()) return;
        $proc = $this->proc();
        $n = 0;
        foreach ($this->faltanEnEste()->get() as $it) { $proc->marcarCrear($it, null, $proc->categoriaSugerida($it)); $n++; }
        $this->mensaje = "Se van a crear $n productos en el " . Instancia::nombreEste() . ' al tocar "Actualizar en Odoo", con los datos del ' . $proc->otro()?->nombre() . '.';
        $this->filtro = 'crear';
        $this->resetPage();
    }

    public function cerrarCrear(): void
    {
        $this->creandoItem = null;
        $this->crearNombre = $this->crearVenta = $this->guardarCodigo = '';
        $this->crearCategoria = '';
        $this->recorriendo = false;
    }

    /** Factura: renglones que están en este Odoo pero no en el otro (ahí no se actualiza el costo ni el stock). */
    private function faltanEnOtro()
    {
        $hay = $this->carga->es_factura && $this->proc()->otro();
        return $this->carga->items()->whereNotNull('odoo_product_id')->whereNull('otro_product_id')
            ->where('crear', false)->where('seleccionado', true)->when(!$hay, fn ($q) => $q->whereRaw('1 = 0'));
    }

    /** Factura: renglones que están en el otro Odoo pero no en este. */
    private function faltanEnEste()
    {
        $hay = $this->carga->es_factura && $this->proc()->otro();
        return $this->carga->items()->whereNull('odoo_product_id')->whereNotNull('otro_product_id')
            ->where('crear', false)->where('match', '!=', 'ignorado')->when(!$hay, fn ($q) => $q->whereRaw('1 = 0'));
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
        AplicarCarga::dispatch($this->carga->id, Instancia::esViejo());
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
            'crear'    => $q->where('crear', true),
            // factura: falta en este Odoo o en el otro
            'falta'    => $q->where('match', '!=', 'ignorado')->where(fn ($w) => $w
                            ->where(fn ($x) => $x->whereNotNull('odoo_product_id')->whereNull('otro_product_id'))
                            ->orWhere(fn ($x) => $x->whereNull('odoo_product_id')->whereNotNull('otro_product_id'))),
            // sin coincidencia en ninguno de los dos
            'gris'     => $q->whereIn('match', ['ninguno', 'ignorado'])->whereNull('otro_product_id'),
            'rojo'     => $q->whereNotNull('costo_anterior')->where('costo_anterior', '>', 0)
                            ->whereRaw('ABS(costo_nuevo / costo_anterior - 1) > 0.30'),
            // los grises son productos que no vende (salvo que estén en el otro Odoo)
            default    => $q->where(fn ($w) => $w->whereNotIn('match', ['ninguno', 'ignorado'])->orWhereNotNull('otro_product_id')),
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
        $conteo = collect(['todos', 'verde', 'amarillo', 'naranja', 'crear', 'gris', 'rojo'])
            ->mapWithKeys(fn ($f) => [$f => $this->filtrarCon($f)->count()])->all();

        // categorías presentes en esta carga (solo productos encontrados)
        $cats = $this->carga->items()->whereNotNull('categoria_id')
            ->selectRaw('categoria_id, COUNT(*) as cant')->groupBy('categoria_id')->pluck('cant', 'categoria_id');
        $categorias = Categoria::whereIn('id', $cats->keys())->orderBy('nombre')->get()
            ->each(function ($c) use ($cats) {
                $c->cant = $cats[$c->id];
                // valores por defecto en los inputs: lo guardado en la categoría
                $this->catMargen[$c->id] ??= $c->margen !== null ? rtrim(rtrim((string) $c->margen, '0'), '.') : '';
                $this->catDescuento[$c->id] ??= $c->descuento_texto ?: ($c->descuento !== null ? rtrim(rtrim((string) $c->descuento, '0'), '.') : '');
                $this->catGuardar[$c->id] ??= true;
            });

        // facturas: también se actualiza el otro Odoo; en listas, el otro solo se usa al crear productos
        $otro = $this->proc()->otro();
        $dosOdoo = $this->carga->es_factura && $otro;
        if ($dosOdoo) $conteo['falta'] = $this->filtrarCon('falta')->count();
        $conCant = fn () => (clone $base)->where('seleccionado', true)->where('cantidad', '>', 0);

        return view('livewire.revisar-carga', [
            'categorias'   => $categorias,
            'items'        => $this->filtrar($this->carga->items())->orderBy('fila')->paginate(50),
            'conteo'       => $conteo,
            'seleccionados'=> (clone $base)->where('seleccionado', true)->whereNotNull('odoo_product_id')->count(),
            'conStock'     => $conCant()->where(fn ($w) => $w->whereNotNull('odoo_product_id')->orWhere('crear', true))->count(),
            'conStockOtro' => $dosOdoo ? $conCant()->where(fn ($w) => $w->whereNotNull('otro_product_id')->orWhere('crear', true))->count() : 0,
            'resultados'   => $this->buscandoItem ? $this->resultadosBusqueda() : [],
            'buscando'     => $this->buscandoItem ? $this->item($this->buscandoItem) : null,
            'pendientes'   => $this->editable() ? (clone $base)->whereIn('match', self::PENDIENTES)->whereNull('odoo_product_id')->count() : 0,
            'sospechosos'  => $this->editable() ? (clone $base)->where('seleccionado', false)->whereNotNull('odoo_product_id')->where('costo_anterior', '>', 0)
                                ->whereRaw('ABS(costo_nuevo / costo_anterior - 1) > ' . Procesador::CAMBIO_SOSPECHOSO)->count() : 0,
            'conPartner'   => (bool) $this->carga->proveedor?->odoo_partner_id,
            'editable'     => $this->editable(),
            'aCrear'       => (clone $base)->where('crear', true)->where('seleccionado', true)->count(),
            'faltanOtro'   => $this->editable() ? $this->faltanEnOtro()->count() : 0,
            'faltanEste'   => $this->editable() ? $this->faltanEnEste()->count() : 0,
            'otroNombre'   => $otro?->nombre(),
            'esteNombre'   => Instancia::nombreEste(),
            'dosOdoo'      => (bool) $dosOdoo,
            'creando'      => $this->creandoItem ? $this->item($this->creandoItem) : null,
            'todasCategorias' => $this->creandoItem ? Categoria::orderBy('nombre')->get(['id', 'nombre']) : collect(),
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
