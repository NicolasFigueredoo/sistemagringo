<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Productos que no están en Odoo: se crean al tocar "Actualizar en Odoo" (en los dos Odoo).
    // Facturas por foto: el renglón también se busca en el otro Odoo, para actualizar el costo ahí y sumar el stock en el viejo.
    public function up(): void
    {
        Schema::table('carga_items', function (Blueprint $t) {
            $t->boolean('crear')->default(false);
            $t->string('crear_nombre')->nullable();
            $t->unsignedBigInteger('crear_categ_id')->nullable();
            $t->unsignedBigInteger('otro_product_id')->nullable();   // el mismo producto en el otro Odoo
            $t->string('otro_nombre')->nullable();
            $t->decimal('otro_costo_anterior', 14, 2)->nullable();
            $t->boolean('otro_aplicado')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('carga_items', function (Blueprint $t) {
            $t->dropColumn(['crear', 'crear_nombre', 'crear_categ_id', 'otro_product_id', 'otro_nombre', 'otro_costo_anterior', 'otro_aplicado']);
        });
    }
};
