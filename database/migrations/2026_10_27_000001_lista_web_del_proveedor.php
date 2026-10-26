<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Dirección donde el proveedor publica su lista: se baja con un botón en vez de subir el archivo
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $t) {
            $t->string('lista_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $t) {
            $t->dropColumn('lista_url');
        });
    }
};
