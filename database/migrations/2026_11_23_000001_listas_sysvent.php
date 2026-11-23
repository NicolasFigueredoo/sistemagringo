<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Listas Sysvent (Angel M. Beber): rubro / marca de cada renglón, precios en dólares o euros con cotización a mano.
    public function up(): void
    {
        Schema::table('cargas', function (Blueprint $t) {
            $t->string('formato', 20)->nullable();             // null = columnas elegidas a mano | sysvent
            $t->decimal('cotizacion_dolar', 14, 4)->nullable();
            $t->decimal('cotizacion_euro', 14, 4)->nullable();
        });
        Schema::table('carga_items', function (Blueprint $t) {
            $t->string('rubro', 120)->nullable()->index();
            $t->string('moneda', 10)->nullable();               // null / ARS | USD | EUR
            $t->decimal('precio_origen', 16, 4)->nullable();   // precio en la moneda de la lista, antes de convertir
        });
    }

    public function down(): void
    {
        Schema::table('carga_items', function (Blueprint $t) {
            $t->dropIndex(['rubro']);
            $t->dropColumn(['rubro', 'moneda', 'precio_origen']);
        });
        Schema::table('cargas', function (Blueprint $t) {
            $t->dropColumn(['formato', 'cotizacion_dolar', 'cotizacion_euro']);
        });
    }
};
