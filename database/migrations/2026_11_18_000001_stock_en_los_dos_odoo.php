<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Facturas por foto: el stock que ingresa se suma en los dos Odoo, cada uno con su "había" y su "ya sumado".
    public function up(): void
    {
        Schema::table('carga_items', function (Blueprint $t) {
            $t->decimal('otro_stock_anterior', 14, 3)->nullable();
            $t->boolean('otro_stock_aplicado')->default(false);
        });

        // Antes, en las facturas cargadas desde la base principal, stock_anterior / stock_aplicado eran los del otro Odoo
        // (el stock se sumaba solo allá). Se pasan a las columnas nuevas para que Deshacer reste en el Odoo correcto.
        if (Schema::getConnection()->getName() === 'sqlite_viejo') return;
        DB::table('carga_items')
            ->whereNotNull('otro_product_id')
            ->whereIn('carga_id', DB::table('cargas')->where('tipo', 'factura')->select('id'))
            ->update([
                'otro_stock_anterior' => DB::raw('stock_anterior'),
                'otro_stock_aplicado' => DB::raw('stock_aplicado'),
                'stock_anterior'      => null,
                'stock_aplicado'      => false,
            ]);
    }

    public function down(): void
    {
        Schema::table('carga_items', function (Blueprint $t) {
            $t->dropColumn(['otro_stock_anterior', 'otro_stock_aplicado']);
        });
    }
};
