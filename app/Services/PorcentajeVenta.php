<?php

namespace App\Services;

use App\Support\Instancia;
use Illuminate\Support\Facades\DB;

/**
 * Precio de venta = costo actual en Odoo × (1 + %) para todos los productos de las categorías elegidas,
 * en el Odoo viejo, en el nuevo o en los dos. Los productos sin costo no se tocan.
 * El % queda guardado como ganancia de la categoría (lo usan también las próximas listas).
 */
class PorcentajeVenta
{
    /** @var callable|null fn(string $paso, int $hechos, int $total) */
    private $avance;

    public function __construct(?callable $avance = null)
    {
        $this->avance = $avance;
    }

    /**
     * @param array<string,float> $porcentajes clave de categoría (CatalogoLocal::claveCategoria) => %
     * @param string[] $destinos 'viejo' y/o 'nuevo'
     * @param int $redondeo 0 = centavos; 10, 50, 100 = redondea para arriba
     * @return array resumen por destino
     */
    public function aplicar(array $porcentajes, array $destinos, int $redondeo = 0): array
    {
        @set_time_limit(0);
        $inicio = microtime(true);
        $resumen = ['destinos' => [], 'categorias' => count($porcentajes)];
        foreach ($destinos as $d) {
            [$cfg, $conexion, $nombre] = $d === 'viejo'
                ? [Instancia::configSecundario(), 'sqlite_viejo', 'Odoo viejo']
                : [Instancia::configPrincipal(), Instancia::conexionPrincipal(), 'Odoo nuevo'];
            $resumen['destinos'][$d] = $this->aplicarEn(OdooClient::desdeArray($cfg), $conexion, $nombre, $porcentajes, $redondeo);
        }
        $resumen['segundos'] = (int) round(microtime(true) - $inicio);
        return $resumen;
    }

    private function aplicarEn(OdooClient $odoo, string $conexion, string $nombre, array $porcentajes, int $redondeo): array
    {
        $r = ['nombre' => $nombre, 'modificados' => 0, 'iguales' => 0, 'sin_costo' => 0, 'faltan' => 0, 'errores' => []];

        $this->paso("$nombre: leyendo categorías y costos");
        $pctPorCateg = [];
        $nombres = [];
        foreach ($odoo->searchRead('product.category', [], ['id', 'complete_name']) as $c) {
            $clave = CatalogoLocal::claveCategoria($c['complete_name']);
            if (!isset($porcentajes[$clave])) continue;
            $pctPorCateg[$c['id']] = (float) $porcentajes[$clave];
            $nombres[$clave] = $c['complete_name'];
        }
        // categorías que no existen en este Odoo
        $r['faltan'] = count(array_diff_key($porcentajes, $nombres));
        if (!$pctPorCateg) return $r;

        // precio de venta en la plantilla (es el que se ve en Odoo); standard_price de la plantilla = costo de su variante
        $plantillas = $odoo->searchReadTodo('product.template', [['categ_id', 'in', array_keys($pctPorCateg)]], ['id', 'standard_price', 'list_price', 'categ_id']);
        $porPrecio = [];   // mismo precio nuevo => una sola escritura
        foreach ($plantillas as $t) {
            $costo = round((float) $t['standard_price'], 2);
            if ($costo <= 0) { $r['sin_costo']++; continue; }
            $precio = Precios::venta($costo, $pctPorCateg[$t['categ_id'][0]], false, $redondeo);
            if (abs($precio - (float) $t['list_price']) < 0.005) { $r['iguales']++; continue; }
            $porPrecio[number_format($precio, 2, '.', '')][] = $t['id'];
        }

        $total = array_sum(array_map('count', $porPrecio));
        $hechos = 0;
        $listos = [];
        $this->paso("$nombre: escribiendo precios de venta", 0, $total);
        foreach (array_chunk($porPrecio, 80, true) as $tanda) {
            $fallas = $odoo->writeMany('product.template', array_map(fn ($ids, $p) => [$ids, ['list_price' => (float) $p]], $tanda, array_keys($tanda)), 8);
            $i = 0;
            foreach ($tanda as $precio => $ids) {
                $hechos += count($ids);
                if (isset($fallas[$i])) {
                    $r['errores'][] = count($ids) . ' producto(s) a $' . $precio . ': ' . mb_substr($fallas[$i], 0, 300);
                } else {
                    $r['modificados'] += count($ids);
                    $listos[$precio] = $ids;
                }
                $i++;
            }
            $this->paso("$nombre: escribiendo precios de venta", $hechos, $total);
        }

        // base local: el precio nuevo y el % como ganancia de la categoría
        $db = DB::connection($conexion);
        $db->transaction(function () use ($db, $listos, $porcentajes, $nombres) {
            foreach ($listos as $precio => $ids) {
                foreach (array_chunk($ids, 500) as $chunk) $db->table('odoo_productos')->whereIn('product_tmpl_id', $chunk)->update(['precio' => (float) $precio]);
            }
            foreach ($nombres as $clave => $nombreCat) {
                $db->table('categorias')->where('nombre', mb_substr($nombreCat, 0, 250))->update(['margen' => $porcentajes[$clave], 'updated_at' => now()]);
            }
        });
        $r['errores'] = array_slice($r['errores'], 0, 30);
        return $r;
    }

    private function paso(string $texto, int $hechos = 0, int $total = 0): void
    {
        if ($this->avance) ($this->avance)($texto, $hechos, $total);
    }
}
