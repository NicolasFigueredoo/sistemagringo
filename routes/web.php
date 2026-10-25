<?php

use App\Http\Controllers\LoginController;
use App\Livewire\Categorias;
use App\Livewire\Historial;
use App\Livewire\Reajustar;
use App\Livewire\RevisarCarga;
use App\Livewire\SubirFactura;
use App\Livewire\SubirLista;
use App\Models\Carga;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Usuarios: php artisan usuario:crear correo@ejemplo.com clave "Nombre"
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', SubirFactura::class)->name('subir');
    // carga por lista de precios (Excel/PDF): fuera del menú, se entra con la dirección
    Route::get('/lista', SubirLista::class)->name('subir.lista');
    Route::get('/carga/{carga}', RevisarCarga::class)->name('revisar');
    Route::get('/carga/{carga}/foto/{n}', function (Carga $carga, int $n) {
        $ruta = $carga->fotos[$n] ?? abort(404);
        return Storage::disk('local')->response($ruta);
    })->name('carga.foto');
    Route::get('/categorias', Categorias::class)->name('categorias');
    Route::get('/historial', Historial::class)->name('historial');
    Route::get('/reajustar', Reajustar::class)->name('reajustar');
});
