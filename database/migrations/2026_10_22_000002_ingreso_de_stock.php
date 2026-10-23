<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Ingreso de stock junto con la lista: la cantidad que entra se suma al stock de Odoo
    public function up(): void
    {
        Schema::table('carga_items', function (Blueprint $t) {
            $t->decimal('cantidad', 14, 3)->nullable();         // unidades que ingresan
            $t->decimal('stock_anterior', 14, 3)->nullable();   // stock en Odoo antes de sumar
            $t->boolean('stock_aplicado')->default(false);
        });
        Schema::table('proveedores', function (Blueprint $t) {
            $t->integer('col_cantidad')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('carga_items', function (Blueprint $t) {
            $t->dropColumn(['cantidad', 'stock_anterior', 'stock_aplicado']);
        });
        Schema::table('proveedores', function (Blueprint $t) {
            $t->dropColumn('col_cantidad');
        });
    }
};
