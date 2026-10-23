<?php

namespace App\Livewire;

use App\Models\Carga;
use App\Models\Categoria;
use App\Models\OdooProducto;
use App\Services\OdooClient;
use App\Services\Procesador;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Reajustar')]
class Reajustar extends Component
{
    public ?string $error = null;

    /** Arma el reajuste de todas las categorías con ganancia (costo actual + su %) y lo abre para revisar. */
    public function crear()
    {
        if (!Categoria::whereNotNull('margen')->exists()) {
            $this->error = 'Ninguna categoría tiene ganancia cargada. Cargala en Categorías.';
            return;
        }

        try {
            [$carga, $salteados] = (new Procesador(OdooClient::desdeConfig()))->crearAjuste();
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'No se pudo conectar con Odoo: ' . $e->getMessage();
            return;
        }

        if ($carga->items()->count() === 0) {
            $carga->delete();
            $this->error = 'Ningún producto de esas categorías tiene costo cargado en Odoo.';
            return;
        }
        if ($salteados) {
            session()->flash('aviso', "$salteados productos sin costo en Odoo no se incluyeron.");
        }
        return $this->redirectRoute('revisar', $carga, navigate: false);
    }

    public function render()
    {
        $conMargen = Categoria::whereNotNull('margen')->pluck('id');

        return view('livewire.reajustar', [
            'categorias'  => $conMargen->count(),
            'productos'   => OdooProducto::whereIn('categ_id', $conMargen)->count(),
            'sinMargen'   => OdooProducto::where(fn ($q) => $q->whereNull('categ_id')->orWhereNotIn('categ_id', $conMargen))->count(),
            'pendiente'   => Carga::whereNull('proveedor_id')->whereNull('categoria_id')->where('estado', 'revision')->latest()->first(),
        ]);
    }
}
