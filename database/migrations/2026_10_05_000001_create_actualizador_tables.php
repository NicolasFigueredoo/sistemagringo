<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Copia local del catálogo de Odoo (para buscar rápido por código de barras / nombre)
        Schema::create('odoo_productos', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();          // id de product.product en Odoo
            $t->string('nombre');
            $t->string('nombre_norm')->index();
            $t->string('barcode')->nullable()->index();
            $t->string('default_code')->nullable()->index();
            $t->decimal('costo', 14, 2)->default(0);
            $t->decimal('precio', 14, 2)->default(0);
            $t->unsignedBigInteger('categ_id')->nullable()->index();   // product.category de Odoo
            $t->timestamps();
        });

        // Categorías de Odoo con su % de ganancia y descuento propios
        Schema::create('categorias', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();          // id de product.category en Odoo
            $t->string('nombre');                             // nombre completo: "Ferretería / Tornillos"
            $t->decimal('margen', 6, 2)->nullable();          // null = usa el del proveedor
            $t->decimal('descuento', 6, 2)->nullable();       // null = usa el general de la carga
            $t->timestamps();
        });

        Schema::create('proveedores', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->unsignedInteger('fila_inicio')->default(1);    // primera fila con datos (1 = primera)
            $t->integer('col_barcode')->nullable();            // índice de columna (0 = A)
            $t->integer('col_codigo')->nullable();
            $t->integer('col_nombre')->nullable();
            $t->integer('col_precio')->nullable();
            $t->boolean('iva_incluido')->default(false);       // la lista trae IVA incluido
            $t->decimal('descuento', 6, 2)->default(0);        // % descuento habitual del proveedor
            $t->decimal('margen', 6, 2)->default(60);          // % ganancia habitual
            $t->timestamps();
        });

        // Asociaciones hechas a mano: la próxima vez salen en verde
        Schema::create('mapeos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $t->string('clave');                               // barcode, código o nombre normalizado
            $t->unsignedBigInteger('odoo_product_id');
            $t->timestamps();
            $t->unique(['proveedor_id', 'clave']);
        });

        Schema::create('cargas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('proveedor_id')->constrained('proveedores');
            $t->string('archivo');
            $t->decimal('descuento', 6, 2)->default(0);
            $t->boolean('iva_incluido')->default(false);
            $t->boolean('sumar_iva')->default(true);
            $t->unsignedInteger('redondeo')->default(0);       // 0, 10, 50, 100
            $t->string('estado')->default('revision');         // revision | aplicada | deshecha
            $t->timestamp('aplicada_at')->nullable();
            $t->timestamp('deshecha_at')->nullable();
            $t->timestamps();
        });

        Schema::create('carga_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('carga_id')->constrained('cargas')->cascadeOnDelete();
            $t->unsignedInteger('fila');
            $t->string('barcode')->nullable();
            $t->string('codigo')->nullable();
            $t->string('nombre')->nullable();
            $t->string('unidad')->nullable();
            $t->decimal('precio_lista', 14, 2);
            $t->unsignedBigInteger('categoria_id')->nullable()->index();
            $t->decimal('descuento', 6, 2)->default(0);
            $t->decimal('costo_nuevo', 14, 2);
            $t->string('match')->default('ninguno');           // barcode | mapeo | nombre | parecido | manual | ninguno
            $t->json('candidatos')->nullable();
            $t->unsignedBigInteger('odoo_product_id')->nullable();
            $t->string('odoo_nombre')->nullable();
            $t->decimal('costo_anterior', 14, 2)->nullable();
            $t->decimal('precio_anterior', 14, 2)->nullable();
            $t->decimal('margen', 6, 2)->default(0);
            $t->decimal('precio_nuevo', 14, 2)->default(0);
            $t->boolean('precio_manual')->default(false);
            $t->boolean('seleccionado')->default(false);
            $t->boolean('aplicado')->default(false);
            $t->string('error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carga_items');
        Schema::dropIfExists('cargas');
        Schema::dropIfExists('mapeos');
        Schema::dropIfExists('proveedores');
        Schema::dropIfExists('categorias');
        Schema::dropIfExists('odoo_productos');
    }
};
