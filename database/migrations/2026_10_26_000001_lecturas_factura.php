<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Cada factura leída con la IA (se paga por lectura): para el tope mensual y para ver el gasto
    public function up(): void
    {
        Schema::create('lecturas_factura', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable();
            $t->unsignedInteger('fotos');
            $t->string('modelo');
            $t->unsignedInteger('tokens_entrada');
            $t->unsignedInteger('tokens_salida');
            $t->timestamps();
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecturas_factura');
    }
};
