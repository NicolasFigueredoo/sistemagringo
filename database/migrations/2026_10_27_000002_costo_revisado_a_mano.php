<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Costo confirmado a mano (sin lista ni factura). Lo que vino por lista o factura sale del historial de cargas.
    public function up(): void
    {
        Schema::table('odoo_productos', function (Blueprint $t) {
            $t->timestamp('costo_revisado_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('odoo_productos', function (Blueprint $t) {
            $t->dropColumn('costo_revisado_at');
        });
    }
};
