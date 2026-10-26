<?php

use App\Http\Controllers\LoginController;
use App\Livewire\Categorias;
use App\Livewire\Costos;
use App\Livewire\Historial;
use App\Livewire\Reajustar;
use App\Livewire\RevisarCarga;
use App\Livewire\SubirFactura;
use App\Livewire\SubirLista;
use App\Http\Middleware\OdooViejo;
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
    Route::get('/', SubirLista::class)->name('subir');
    // carga por foto de factura: fuera del menú, se entra con la dirección
    Route::get('/factura', SubirFactura::class)->name('subir.factura');
    Route::get('/carga/{carga}', RevisarCarga::class)->name('revisar');
    Route::get('/carga/{carga}/foto/{n}', function (Carga $carga, int $n) {
        $ruta = $carga->fotos[$n] ?? abort(404);
        return Storage::disk('local')->response($ruta);
    })->name('carga.foto');
    Route::get('/categorias', Categorias::class)->name('categorias');
    Route::get('/costos', Costos::class)->name('costos');
    Route::get('/historial', Historial::class)->name('historial');
    Route::get('/reajustar', Reajustar::class)->name('reajustar');

    // Odoo viejo: solo facturas por foto, con su propia base (catálogo, proveedores, historial)
    Route::prefix('viejo')->name('viejo.')->middleware(OdooViejo::class)->group(function () {
        Route::get('/', SubirFactura::class)->name('subir.factura');
        Route::get('/carga/{carga}', RevisarCarga::class)->name('revisar');
        Route::get('/carga/{carga}/foto/{n}', function (Carga $carga, int $n) {
            $ruta = $carga->fotos[$n] ?? abort(404);
            return Storage::disk('local')->response($ruta);
        })->name('carga.foto');
        Route::get('/historial', Historial::class)->name('historial');
    });
});
