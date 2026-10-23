<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Una carga puede ser un "ajuste de categoría": sin proveedor, recalcula la venta desde el costo actual de Odoo
    public function up(): void
    {
        Schema::table('cargas', function (Blueprint $t) {
            $t->unsignedBigInteger('proveedor_id')->nullable()->change();
            $t->unsignedBigInteger('categoria_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('cargas', function (Blueprint $t) {
            $t->dropIndex(['categoria_id']);
            $t->dropColumn('categoria_id');
        });
    }
};
