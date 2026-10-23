<?php

namespace App\Livewire;

use App\Models\OdooProducto;
use App\Models\Proveedor;
use App\Services\LectorLista;
use App\Services\OdooClient;
use App\Services\Precios;
use App\Services\Procesador;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('Cargar lista')]
class SubirLista extends Component
{
    use WithFileUploads;

    public $archivo;
    public $proveedorId = '';
    public string $nuevoProveedor = '';

    public array $preview = [];
    public int $ncols = 0;
    public int $totalFilas = 0;
    public array $columnas = ['fila_inicio' => 1, 'col_barcode' => null, 'col_codigo' => null, 'col_nombre' => null, 'col_precio' => null, 'col_cantidad' => null];

    public $descuento = 0;
    public $margen = 60;
    public bool $ivaIncluido = false;
    public bool $sumarIva = true;

    public ?string $error = null;
    public ?string $aviso = null;

    /** Se llama al abrir la página (wire:init): refresca proveedores de Odoo sin bloquear la carga. */
    public function cargarProveedores(): void
    {
        if (Cache::has('proveedores_odoo_sync')) return;
        try {
            (new Procesador(OdooClient::desdeConfig()))->sincronizarProveedores();
            Cache::put('proveedores_odoo_sync', true, now()->addMinutes(10));
        } catch (\Throwable) {
            // sin conexión a Odoo: se muestran los proveedores ya guardados
        }
    }

    public function updatedProveedorId(): void
    {
        if ($p = Proveedor::find($this->proveedorId)) {
            $this->descuento = (float) $p->descuento;
            $this->margen = (float) $p->margen;
            $this->ivaIncluido = (bool) $p->iva_incluido;
            if ($this->preview && $p->col_precio !== null) {
                $this->columnas = $p->only(['fila_inicio', 'col_barcode', 'col_codigo', 'col_nombre', 'col_precio', 'col_cantidad']);
            }
        }
    }

    public function updatedArchivo(): void
    {
        $this->error = null;
        $this->validate(['archivo' => 'required|file|max:20480|mimes:xlsx,xls,ods,csv,txt,pdf']);

        try {
            $filas = LectorLista::leer($this->archivo->getRealPath(), $this->archivo->getClientOriginalExtension());
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->preview = [];
            return;
        }

        if (!$filas) {
            $this->error = 'El archivo está vacío o no se pudo leer.';
            return;
        }

        $this->totalFilas = count($filas);
        $this->preview = array_slice($filas, 0, 15);
        $this->ncols = max(array_map('count', $this->preview));

        $p = Proveedor::find($this->proveedorId);
        $this->columnas = ($p && $p->col_precio !== null)
            ? $p->only(['fila_inicio', 'col_barcode', 'col_codigo', 'col_nombre', 'col_precio', 'col_cantidad'])
            : LectorLista::detectarColumnas($filas);
    }

    public function sincronizar(): void
    {
        try {
            $n = (new Procesador(OdooClient::desdeConfig()))->sincronizarCatalogo();
            $this->aviso = "Catálogo actualizado: $n productos.";
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = 'No se pudo conectar con Odoo: ' . $e->getMessage();
        }
    }

    public function procesar()
    {
        $this->error = null;

        if (!$this->archivo) { $this->error = 'Primero elegí el archivo.'; return; }
        if (!$this->proveedorId) { $this->error = 'Elegí el proveedor.'; return; }
        if ($this->proveedorId === 'nuevo' && trim($this->nuevoProveedor) === '') { $this->error = 'Escribí el nombre del proveedor nuevo.'; return; }
        if ($this->columnas['col_precio'] === null || $this->columnas['col_precio'] === '') { $this->error = 'Indicá cuál columna es el PRECIO.'; return; }
        if (($this->columnas['col_barcode'] ?? '') === '' && ($this->columnas['col_nombre'] ?? '') === '' && ($this->columnas['col_codigo'] ?? '') === '') {
            $this->error = 'Indicá al menos la columna de CÓDIGO DE BARRAS o la de DESCRIPCIÓN.'; return;
        }
        if (OdooProducto::count() === 0) { $this->error = 'Falta bajar el catálogo de Odoo. Tocá "Actualizar catálogo".'; return; }

        $cols = array_map(fn ($v) => ($v === '' || $v === null) ? null : (int) $v, $this->columnas);
        $cols['fila_inicio'] = max(1, (int) ($this->columnas['fila_inicio'] ?: 1));

        $prov = $this->proveedorId === 'nuevo'
            ? Proveedor::firstOrCreate(['nombre' => trim($this->nuevoProveedor)])
            : Proveedor::findOrFail($this->proveedorId);

        $prov->update($cols + [
            'descuento'    => Precios::numero($this->descuento) ?? 0,
            'margen'       => Precios::numero($this->margen) ?? 0,
            'iva_incluido' => $this->ivaIncluido,
        ]);

        try {
            $carga = (new Procesador(OdooClient::desdeConfig()))->crearCarga(
                $prov,
                $this->archivo->getRealPath(),
                $this->archivo->getClientOriginalExtension(),
                $this->archivo->getClientOriginalName(),
                $cols,
                [
                    'descuento'    => Precios::numero($this->descuento) ?? 0,
                    'iva_incluido' => $this->ivaIncluido,
                    'sumar_iva'    => $this->sumarIva,
                    'redondeo'     => 0,
                ]
            );
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'Error al procesar: ' . $e->getMessage();
            return;
        }

        if ($carga->items()->count() === 0) {
            $this->error = 'No se encontró ningún renglón con precio. Revisá las columnas elegidas.';
            $carga->delete();
            return;
        }

        return $this->redirectRoute('revisar', $carga, navigate: false);
    }

    public function render()
    {
        return view('livewire.subir-lista', [
            'proveedores'      => Proveedor::orderByRaw('LOWER(nombre)')->get(['id', 'nombre']),
            'catalogo'         => OdooProducto::count(),
            'catalogoFecha'    => OdooProducto::max('updated_at'),
        ]);
    }
}
