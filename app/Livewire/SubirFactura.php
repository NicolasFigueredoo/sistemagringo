<?php

namespace App\Livewire;

use App\Models\LecturaFactura;
use App\Models\OdooProducto;
use App\Models\Proveedor;
use App\Services\LectorFactura;
use App\Services\Matcher;
use App\Services\OdooClient;
use App\Services\Precios;
use App\Support\Instancia;
use App\Services\Procesador;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
#[Title('Cargar factura')]
class SubirFactura extends Component
{
    use WithFileUploads;

    public $proveedorId = '';
    public string $nuevoProveedor = '';

    public $nuevas = [];        // lo que se acaba de sacar o adjuntar
    public array $fotos = [];   // todas las páginas de la factura
    public string $iva = 'auto';   // auto | tal_cual | sacar | agregar
    public string $descuentoExtra = '';   // se suma al que trae la factura; acepta "50+13"

    public ?string $error = null;
    public ?string $aviso = null;

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

    public function updatedNuevas(): void
    {
        $this->error = null;
        // por extensión y no por contenido (algunos celulares mandan fotos que no se reconocen por contenido)
        $this->validate(
            ['nuevas.*' => ['file', 'max:20480', function ($attr, $valor, $falla) {
                if (!in_array(strtolower($valor->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
                    $falla('Solo fotos (JPG, PNG) o PDF.');
                }
            }]],
            ['nuevas.*.max' => 'Cada archivo puede pesar hasta 20 MB.']
        );
        $this->fotos = array_merge($this->fotos, is_array($this->nuevas) ? $this->nuevas : [$this->nuevas]);
        $this->nuevas = [];
    }

    public function quitarFoto(int $i): void
    {
        unset($this->fotos[$i]);
        $this->fotos = array_values($this->fotos);
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

        if (!$this->fotos) { $this->error = 'Sacá o adjuntá la foto de la factura.'; return; }
        if ($this->proveedorId === 'nuevo' && trim($this->nuevoProveedor) === '') { $this->error = 'Escribí el nombre del proveedor nuevo.'; return; }
        if (OdooProducto::count() === 0) { $this->error = 'Falta bajar el catálogo de Odoo. Tocá "Actualizar catálogo".'; return; }
        if (($tope = LecturaFactura::tope()) && LecturaFactura::delMes() >= $tope) {
            $this->error = "Se llegó al límite de $tope facturas de este mes. Para cargar más, consultá por la ampliación del plan.";
            return;
        }

        @set_time_limit(0);   // la IA tarda hasta un minuto en leer la factura
        try {
            $factura = LectorFactura::desdeConfig()->leer(array_map(fn ($f) => $f->getRealPath(), $this->fotos));
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'No se pudo leer la factura: ' . $e->getMessage();
            return;
        }
        if (!$factura['items']) { $this->error = 'No se encontró ningún producto en la foto. Probá con una foto más nítida y derecha.'; return; }

        // descuento adicional sobre el que trae la factura: 10% de la factura + "50+13" = 10+50+13
        if (Precios::descuento($this->descuentoExtra) !== null) {
            $general = (float) $factura['descuento_general'];
            $texto = ($general > 0 ? rtrim(rtrim((string) round($general, 2), '0'), '.') . '+' : '') . preg_replace('/\s+/', '', $this->descuentoExtra);
            $factura['descuento_general'] = Precios::descuento($texto);
            $factura['descuento_texto'] = Precios::textoDescuento($texto);
        }

        $prov = match (true) {
            $this->proveedorId === 'nuevo' => Proveedor::firstOrCreate(['nombre' => trim($this->nuevoProveedor)]),
            (bool) $this->proveedorId      => Proveedor::findOrFail($this->proveedorId),
            default                        => self::proveedorPorNombre($factura['proveedor']),
        };
        if (!$prov) {
            $this->error = 'No reconocí el proveedor' . ($factura['proveedor'] ? " \"{$factura['proveedor']}\"" : '') . ': elegilo de la lista.';
            return;
        }

        [$sacar, $agregar] = match ($this->iva) {
            'sacar'    => [true, false],
            'agregar'  => [false, true],
            'tal_cual' => [false, false],
            default    => [$factura['precios_con_iva'], false],
        };

        $dir = 'facturas/' . now()->format('Y-m');
        $rutas = array_map(fn ($f) => $f->store($dir, 'local'), $this->fotos);

        try {
            $carga = (new Procesador(OdooClient::desdeConfig()))->crearCargaFactura($prov, $factura, $rutas, $sacar, $agregar);
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'Error al procesar: ' . $e->getMessage();
            return;
        }

        if ($carga->items()->count() === 0) {
            $this->error = 'La factura no tiene renglones con precio.';
            $carga->delete();
            return;
        }

        return $this->redirectRoute(Instancia::nombreRuta('revisar'), $carga, navigate: false);
    }

    /** Proveedor cuyo nombre se parece al que figura en la factura ("FERRETERA DEL SUR S.A." ~ "Ferretera del Sur"). */
    private static function proveedorPorNombre(?string $nombre): ?Proveedor
    {
        if (!$nombre) return null;
        $buscado = Matcher::tokens(Matcher::normalizar($nombre));
        $sacar = ['sa', 'srl', 'sas', 'saic', 'sacif', 'de', 'del', 'la', 'el', 'y'];
        $buscado = array_values(array_diff($buscado, $sacar));
        if (!$buscado) return null;

        $mejor = null;
        $puntaje = 0;
        foreach (Proveedor::all(['id', 'nombre']) as $p) {
            $t = array_diff(Matcher::tokens(Matcher::normalizar($p->nombre)), $sacar);
            if (!$t) continue;
            $comunes = count(array_intersect($buscado, $t));
            $s = $comunes / max(count($buscado), count($t));
            if ($s > $puntaje) [$mejor, $puntaje] = [$p, $s];
        }
        return $puntaje >= 0.6 ? $mejor : null;
    }

    public function render()
    {
        return view('livewire.subir-factura', [
            'proveedores'   => Proveedor::orderByRaw('LOWER(nombre)')->get(['id', 'nombre']),
            'catalogo'      => OdooProducto::count(),
            'catalogoFecha' => OdooProducto::max('updated_at'),
            'leidasMes'     => LecturaFactura::delMes(),
            'tope'          => LecturaFactura::tope(),
        ]);
    }
}
