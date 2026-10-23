<?php

namespace App\Livewire;

use App\Models\Carga;
use App\Services\OdooClient;
use App\Services\Procesador;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Historial')]
class Historial extends Component
{
    use WithPagination;

    public ?string $mensaje = null;
    public ?string $error = null;

    public function deshacer(int $id): void
    {
        $carga = Carga::findOrFail($id);
        if ($carga->estado !== 'aplicada') return;

        try {
            $n = (new Procesador(OdooClient::desdeConfig()))->deshacer($carga);
            $this->mensaje = "Listo: se volvieron $n productos al precio anterior.";
            $this->error = null;
        } catch (\Throwable $e) {
            report($e);
            $this->error = 'No se pudo deshacer: ' . $e->getMessage();
        }
    }

    public function eliminar(int $id): void
    {
        $carga = Carga::findOrFail($id);
        if ($carga->estado === 'revision') $carga->delete();   // solo borradores
    }

    public function render()
    {
        return view('livewire.historial', [
            'cargas' => Carga::with('proveedor', 'categoria')
                ->withCount(['items', 'items as aplicados_count' => fn ($q) => $q->where('aplicado', true)])
                ->latest()->paginate(20),
        ]);
    }
}
