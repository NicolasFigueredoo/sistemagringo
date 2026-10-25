<?php

namespace App\Livewire;

use App\Models\OdooProducto;
use App\Models\Proveedor;
use App\Services\LectorFactura;
use App\Services\Matcher;
use App\Services\OdooClient;
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
        $this->validate(
            ['nuevas.*' => 'file|max:12288|mimes:jpg,jpeg,png,webp,pdf'],
            ['nuevas.*.mimes' => 'Solo fotos (JPG, PNG) o PDF.', 'nuevas.*.max' => 'Cada archivo puede pesar hasta 12 MB.']
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

        try {
            $factura = LectorFactura::desdeConfig()->leer(array_map(fn ($f) => $f->getRealPath(), $this->fotos));
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'No se pudo leer la factura: ' . $e->getMessage();
            return;
        }
        if (!$factura['items']) { $this->error = 'No se encontró ningún producto en la foto. Probá con una foto más nítida y derecha.'; return; }

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

        return $this->redirectRoute('revisar', $carga, navigate: false);
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
        ]);
    }
}
