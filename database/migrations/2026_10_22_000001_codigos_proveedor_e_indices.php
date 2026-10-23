<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // para pasar del producto plantilla (product.template) a sus variantes
        Schema::table('odoo_productos', function (Blueprint $t) {
            $t->unsignedBigInteger('product_tmpl_id')->nullable()->index();
        });

        // contacto del proveedor en Odoo (res.partner)
        Schema::table('proveedores', function (Blueprint $t) {
            $t->unsignedBigInteger('odoo_partner_id')->nullable()->index();
        });

        // código que usa cada proveedor para el producto (product.supplierinfo en Odoo, pestaña Compra)
        Schema::create('odoo_codigos_proveedor', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('partner_id');
            $t->string('codigo');                              // normalizado con Matcher::codigoClave
            $t->unsignedBigInteger('product_id');
            $t->index(['partner_id', 'codigo']);
        });

        // SQLite no crea índices para las claves foráneas: sin esto cada pantalla recorre todos los renglones
        Schema::table('carga_items', function (Blueprint $t) {
            $t->index(['carga_id', 'fila']);
            $t->index(['carga_id', 'match']);
            $t->index(['carga_id', 'categoria_id']);
            $t->index(['carga_id', 'seleccionado']);
        });
    }

    public function down(): void
    {
        Schema::table('carga_items', function (Blueprint $t) {
            $t->dropIndex(['carga_id', 'fila']);
            $t->dropIndex(['carga_id', 'match']);
            $t->dropIndex(['carga_id', 'categoria_id']);
            $t->dropIndex(['carga_id', 'seleccionado']);
        });
        Schema::dropIfExists('odoo_codigos_proveedor');
        Schema::table('proveedores', function (Blueprint $t) {
            $t->dropIndex(['odoo_partner_id']);
            $t->dropColumn('odoo_partner_id');
        });
        Schema::table('odoo_productos', function (Blueprint $t) {
            $t->dropIndex(['product_tmpl_id']);
            $t->dropColumn('product_tmpl_id');
        });
    }
};
