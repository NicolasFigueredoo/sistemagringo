<?php

use App\Http\Controllers\LoginController;
use App\Livewire\Categorias;
use App\Livewire\Historial;
use App\Livewire\RevisarCarga;
use App\Livewire\SubirLista;
use Illuminate\Support\Facades\Route;

// Usuarios: php artisan usuario:crear correo@ejemplo.com clave "Nombre"
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', SubirLista::class)->name('subir');
    Route::get('/carga/{carga}', RevisarCarga::class)->name('revisar');
    Route::get('/categorias', Categorias::class)->name('categorias');
    Route::get('/historial', Historial::class)->name('historial');
});
