<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Descuentos encadenados ("50+13"): el texto se guarda para mostrarlo, "descuento" sigue siendo el % equivalente.
    // Costo escrito a mano en la revisión. Lecturas con IA de listas (no cuentan para el tope de facturas).
    public function up(): void
    {
        foreach (['proveedores', 'categorias', 'cargas'] as $tabla) {
            Schema::table($tabla, function (Blueprint $t) {
                $t->string('descuento_texto', 50)->nullable();
            });
        }
        Schema::table('carga_items', function (Blueprint $t) {
            $t->boolean('costo_manual')->default(false);
        });
        Schema::table('lecturas_factura', function (Blueprint $t) {
            $t->string('tipo', 10)->default('factura');
        });
    }

    public function down(): void
    {
        foreach (['proveedores', 'categorias', 'cargas'] as $tabla) {
            Schema::table($tabla, function (Blueprint $t) {
                $t->dropColumn('descuento_texto');
            });
        }
        Schema::table('carga_items', function (Blueprint $t) {
            $t->dropColumn('costo_manual');
        });
        Schema::table('lecturas_factura', function (Blueprint $t) {
            $t->dropColumn('tipo');
        });
    }
};
