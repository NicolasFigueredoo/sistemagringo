<?php

namespace App\Console\Commands;

use App\Services\OdooClient;
use App\Services\Procesador;
use App\Support\Instancia;
use Illuminate\Console\Command;

class SincronizarOdoo extends Command
{
    protected $signature = 'odoo:sincronizar {--viejo : el Odoo viejo (facturas por foto)}';
    protected $description = 'Baja el catálogo de productos de Odoo a la base local';

    public function handle(): int
    {
        Instancia::usar((bool) $this->option('viejo'));
        $n = (new Procesador(OdooClient::desdeConfig()))->sincronizarCatalogo(
            fn ($total) => $this->line("  $total productos...")
        );
        $this->info("Catálogo sincronizado: $n productos.");
        return self::SUCCESS;
    }
}
