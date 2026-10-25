<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Carga desde la foto de una factura: solo costo y stock, el precio de venta no se toca
    public function up(): void
    {
        Schema::table('cargas', function (Blueprint $t) {
            $t->string('tipo')->default('lista');           // lista | factura
            $t->json('fotos')->nullable();                  // rutas en storage/app/private
            $t->boolean('agregar_iva')->default(false);     // la factura viene sin IVA y el costo se guarda con IVA
        });
    }

    public function down(): void
    {
        Schema::table('cargas', function (Blueprint $t) {
            $t->dropColumn(['tipo', 'fotos', 'agregar_iva']);
        });
    }
};
