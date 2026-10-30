<?php

namespace App\Livewire;

use App\Models\OdooProducto;
use App\Models\Proveedor;
use App\Services\DescargaLista;
use App\Services\LectorLista;
use App\Services\LectorListaIA;
use App\Services\OdooClient;
use App\Services\Precios;
use App\Services\Procesador;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('Cargar lista')]
class SubirLista extends Component
{
    use WithFileUploads;

    public $archivo;
    // lista bajada de la web del proveedor (en vez de subir el archivo)
    public string $listaUrl = '';
    #[Locked] public ?array $descargada = null;   // ruta, ext, nombre
    public $proveedorId = '';
    public string $nuevoProveedor = '';

    /** PDF leyéndose con IA de a tramos: hecho, total */
    #[Locked] public ?array $leyendoIA = null;

    public array $preview = [];
    public int $ncols = 0;
    public int $totalFilas = 0;
    public array $columnas = ['fila_inicio' => 1, 'col_barcode' => null, 'col_codigo' => null, 'col_nombre' => null, 'col_precio' => null, 'col_cantidad' => null];

    public $descuento = 0;
    public bool $ivaIncluido = false;

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
            $this->descuento = $p->descuento_texto ?: rtrim(rtrim((string) $p->descuento, '0'), '.');
            $this->ivaIncluido = (bool) $p->iva_incluido;
            $this->listaUrl = (string) $p->lista_url;
            if ($this->preview && $p->col_precio !== null) {
                $this->columnas = $p->only(['fila_inicio', 'col_barcode', 'col_codigo', 'col_nombre', 'col_precio', 'col_cantidad']);
            }
        }
    }

    public function updatedArchivo(): void
    {
        $this->error = null;
        // por extensión y no por contenido: muchos sistemas exportan xls/xlsx que no se reconocen por contenido
        $this->validate(
            ['archivo' => ['required', 'file', 'max:51200', function ($attr, $valor, $falla) {
                if (!in_array(strtolower($valor->getClientOriginalExtension()), self::EXTENSIONES, true)) {
                    $falla('Formato no soportado: subí la lista en Excel (xlsx, xls, ods), CSV o PDF.');
                }
            }]],
            ['archivo.max' => 'La lista puede pesar hasta 50 MB.']
        );
        $this->descargada = null;
        $this->leerArchivo();
    }

    private const EXTENSIONES = ['xlsx', 'xls', 'ods', 'csv', 'txt', 'pdf'];

    /** Baja la lista desde la web del proveedor y sigue igual que si se hubiera subido el archivo. */
    public function traerDeWeb(): void
    {
        $this->error = null;
        if (trim($this->listaUrl) === '') { $this->error = 'Pegá la dirección de la lista.'; return; }
        try {
            $this->descargada = DescargaLista::bajar($this->listaUrl);
        } catch (\Throwable $e) {
            $this->error = 'No se pudo bajar la lista: ' . $e->getMessage();
            return;
        }
        $this->archivo = null;
        $this->leerArchivo();
    }

    /** @return array{ruta:string, ext:string, nombre:string}|null el archivo subido o el bajado de la web */
    private function fuente(): ?array
    {
        if ($this->descargada) return $this->descargada;
        if (!$this->archivo) return null;
        return ['ruta' => $this->archivo->getRealPath(), 'ext' => $this->archivo->getClientOriginalExtension(), 'nombre' => $this->archivo->getClientOriginalName()];
    }

    private function leerArchivo(): void
    {
        $f = $this->fuente();
        $this->leyendoIA = null;
        try {
            // PDF con IA: un tramo por pedido (seguirLeyendo), así ningún pedido pasa el límite del servidor
            if (strtolower($f['ext']) === 'pdf' && LectorListaIA::disponible() && !LectorLista::yaLeida($f['ruta'], 'pdf')) {
                $tramos = LectorListaIA::tramos(LectorLista::textoPdf($f['ruta']), LectorListaIA::TRAMO_WEB);
                Cache::put($this->claveLectura($f), ['tramos' => $tramos, 'respuestas' => [], 'fallos' => 0, 'control' => LectorLista::textoControl($f['ruta'])], now()->addHours(3));
                $this->leyendoIA = ['hecho' => 0, 'total' => count($tramos)];
                $this->preview = [];
                $this->aviso = null;
                $this->js('$wire.seguirLeyendo()');
                return;
            }
            $filas = LectorLista::leer($f['ruta'], $f['ext']);
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->preview = [];
            return;
        }
        $this->mostrar($filas);
    }

    /** Lee el próximo tramo del PDF con IA; al terminar arma la vista previa. Se llama solo, un pedido tras otro. */
    public function seguirLeyendo(): void
    {
        if (!$this->leyendoIA || !$f = $this->fuente()) return;
        $estado = Cache::get($clave = $this->claveLectura($f));
        if (!$estado) {
            $this->leyendoIA = null;
            $this->error = 'Se cortó la lectura de la lista: volvé a elegir el archivo.';
            return;
        }

        // varios tramos a la vez; el que falla se reintenta en la vuelta siguiente (hasta 4 fallas en total)
        $falla = null;
        try {
            @set_time_limit(0);
            $pendientes = array_slice(array_diff_key($estado['tramos'], $estado['respuestas']), 0, LectorListaIA::A_LA_VEZ, true);
            foreach (LectorListaIA::desdeConfig()->intentarVarios($pendientes, $estado['control'] ?? null) as $i => $r) {
                if (!$r instanceof \Throwable) { $estado['respuestas'][$i] = $r; continue; }
                report($r);
                $estado['fallos'] = ($estado['fallos'] ?? 0) + 1;
                if ($estado['fallos'] > 4) throw $r;
            }
        } catch (\Throwable $e) {
            $falla = 'No se pudo leer con IA (' . $e->getMessage() . '); se leyó sin IA, revisá los renglones.';
        }
        ksort($estado['respuestas']);

        if (!$falla && count($estado['respuestas']) < count($estado['tramos'])) {
            Cache::put($clave, $estado, now()->addHours(3));
            $this->leyendoIA['hecho'] = count($estado['respuestas']);
            $this->js('$wire.seguirLeyendo()');
            return;
        }

        Cache::forget($clave);
        $this->leyendoIA = null;
        try {
            $r = $falla ? null : LectorListaIA::aFilas($estado['respuestas']);
            $filas = $r && $r['filas']
                ? LectorLista::guardarIA($f['ruta'], $r)
                : LectorLista::leerSinIA($f['ruta'], $falla ?? 'La IA no encontró productos; se leyó sin IA.');
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            return;
        }
        $this->mostrar($filas);
    }

    private function claveLectura(array $f): string
    {
        return 'lectura-ia:' . sha1_file($f['ruta']);
    }

    private function mostrar(array $filas): void
    {
        if (!$filas) {
            $this->error = 'El archivo está vacío o no se pudo leer.';
            return;
        }

        $this->totalFilas = count($filas);
        $this->preview = array_slice($filas, 0, 15);
        $this->ncols = max(array_map('count', $this->preview));

        $p = Proveedor::find($this->proveedorId);
        $sug = LectorLista::$sugerencias;
        // la IA arma ella misma las columnas: las guardadas del proveedor no sirven para esa forma
        $this->columnas = ($p && $p->col_precio !== null && empty($sug['ia']))
            ? $p->only(['fila_inicio', 'col_barcode', 'col_codigo', 'col_nombre', 'col_precio', 'col_cantidad'])
            : LectorLista::detectarColumnas($filas);

        $avisos = [];
        if (!empty($sug['ia'])) {
            $avisos[] = 'Lista leída con IA: ' . ($this->totalFilas - 1) . ' productos.'
                . (!empty($sug['columna_costo']) ? " Se toma como costo la columna \"{$sug['columna_costo']}\" (podés cambiarla abajo)." : '');
            if (!empty($sug['precios_con_iva'])) {
                $this->ivaIncluido = true;
                $avisos[] = 'Los precios incluyen IVA: se marcó "La lista ya incluye IVA".';
            }
        }
        if (!empty($sug['aviso'])) $avisos[] = $sug['aviso'];
        $this->aviso = $avisos ? implode(' ', $avisos) : null;
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

        if (!$f = $this->fuente()) { $this->error = 'Primero elegí el archivo o traé la lista de la web.'; return; }
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

        $descuento = Precios::descuento($this->descuento) ?? 0;
        $descuentoTexto = Precios::textoDescuento($this->descuento);

        $prov->update($cols + [
            'descuento'       => $descuento,
            'descuento_texto' => $descuentoTexto,
            'iva_incluido' => $this->ivaIncluido,
        ] + ($this->descargada ? ['lista_url' => trim($this->listaUrl)] : []));

        try {
            $carga = (new Procesador(OdooClient::desdeConfig()))->crearCarga(
                $prov,
                $f['ruta'],
                $f['ext'],
                $f['nombre'],
                $cols,
                [
                    'descuento'       => $descuento,
                    'descuento_texto' => $descuentoTexto,
                    'iva_incluido' => $this->ivaIncluido,
                    'sumar_iva'    => true,   // se puede cambiar en la revisión
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
