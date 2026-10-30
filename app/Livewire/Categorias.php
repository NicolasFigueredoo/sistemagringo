<?php

namespace App\Livewire;

use App\Models\Categoria;
use App\Models\OdooProducto;
use App\Services\OdooClient;
use App\Services\Precios;
use App\Services\Procesador;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Categorías')]
class Categorias extends Component
{
    use WithPagination;

    public string $buscar = '';
    public bool $soloConValores = false;
    public ?string $mensaje = null;
    public ?string $error = null;

    public function updatedBuscar(): void { $this->resetPage(); }
    public function updatedSoloConValores(): void { $this->resetPage(); }

    /** $campo = margen | descuento. Vacío = usar el general. */
    public function guardar(int $id, string $campo, $valor): void
    {
        if (!in_array($campo, ['margen', 'descuento'], true)) return;
        if ($campo === 'descuento') {
            // acepta descuentos encadenados: "50+13"
            Categoria::where('id', $id)->update(['descuento' => Precios::descuento($valor), 'descuento_texto' => Precios::textoDescuento($valor)]);
        } else {
            Categoria::where('id', $id)->update(['margen' => trim((string) $valor) === '' ? null : Precios::numero($valor)]);
        }
        $this->mensaje = 'Guardado.';
    }

    /** Arma el ajuste de precios de la categoría (costo actual + su ganancia) y lo abre para revisar. */
    public function ajustar(int $id)
    {
        $cat = Categoria::findOrFail($id);
        if ($cat->margen === null) { $this->error = "Primero poné la ganancia % de \"{$cat->nombre}\"."; return; }

        try {
            [$carga, $salteados] = (new Procesador(OdooClient::desdeConfig()))->crearAjuste($cat);
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'No se pudo conectar con Odoo: ' . $e->getMessage();
            return;
        }

        if ($carga->items()->count() === 0) {
            $carga->delete();
            $this->error = "Ningún producto de \"{$cat->nombre}\" tiene costo cargado en Odoo.";
            return;
        }
        if ($salteados) {
            session()->flash('aviso', "$salteados productos sin costo en Odoo no se incluyeron.");
        }
        return $this->redirectRoute('revisar', $carga, navigate: false);
    }

    public function sincronizar(): void
    {
        try {
            $n = (new Procesador(OdooClient::desdeConfig()))->sincronizarCatalogo();
            $this->mensaje = "Categorías y productos actualizados desde Odoo ($n productos).";
            $this->error = null;
        } catch (\Throwable $e) {
            $this->error = 'No se pudo conectar con Odoo: ' . $e->getMessage();
        }
    }

    public function render()
    {
        $q = Categoria::query()->orderBy('nombre');
        if (trim($this->buscar) !== '') $q->where('nombre', 'like', '%' . trim($this->buscar) . '%');
        if ($this->soloConValores) $q->where(fn ($w) => $w->whereNotNull('margen')->orWhereNotNull('descuento'));

        $cats = $q->paginate(40);
        $conteo = OdooProducto::whereIn('categ_id', $cats->pluck('id'))
            ->selectRaw('categ_id, COUNT(*) as n')->groupBy('categ_id')->pluck('n', 'categ_id');

        return view('livewire.categorias', ['categorias' => $cats, 'conteo' => $conteo]);
    }
}
