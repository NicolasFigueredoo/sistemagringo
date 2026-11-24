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

#[Layout('layouts.app')]
#[Title('Categorías')]
class Categorias extends Component
{
    public string $buscar = '';
    public bool $soloConValores = false;
    public ?string $mensaje = null;
    public ?string $error = null;

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

        // todas en una sola página: son pocas y así se ven completas
        $cats = $q->get();
        $conteo = OdooProducto::whereIn('categ_id', $cats->pluck('id'))
            ->selectRaw('categ_id, COUNT(*) as n')->groupBy('categ_id')->pluck('n', 'categ_id');

        return view('livewire.categorias', ['categorias' => $cats, 'conteo' => $conteo]);
    }
}
