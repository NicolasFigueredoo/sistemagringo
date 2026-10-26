<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // "Actualizar en Odoo" corre en segundo plano por tandas (estado = aplicando): esto es el avance para mostrar
    public function up(): void
    {
        Schema::table('cargas', function (Blueprint $t) {
            $t->unsignedInteger('progreso_total')->nullable();
            $t->unsignedInteger('progreso_hecho')->nullable();
            $t->unsignedInteger('progreso_errores')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cargas', function (Blueprint $t) {
            $t->dropColumn(['progreso_total', 'progreso_hecho', 'progreso_errores']);
        });
    }
};
