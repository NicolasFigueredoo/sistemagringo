<?php

namespace App\Services;

/**
 * Listas de precios exportadas de Sysvent (Angel M. Beber S.A.).
 *
 *   Angel M.BEBER S.A.
 *   03/11/2026
 *   Sysvent: LISTA DE PRECIOS
 *   Codigo | Descripcion | (cód. fabricante) | Pr.Lista | [Pr.Neto] | %IVA | [Pr.c/IVA] | Moneda | Ult.Mod.Pr.
 *   -----------------  | Rubro: 002-AJUSTABLES
 *   035810 | AJUSTABLE*ACESA 700-6 ****** | | 9399.09 | 21 | Pesos | 06/07/2026
 *
 * "Codigo" es el código INTERNO de Beber (sirve para recordar la asociación, no para buscar en Odoo):
 * el código del producto viene dentro de la descripción (ver CodigosDescripcion).
 */
class LectorSysvent
{
    public static function es(array $filas): bool
    {
        foreach (array_slice($filas, 0, 6) as $f) {
            if (preg_match('/sysvent/i', implode(' ', array_map('strval', $f)))) return true;
        }
        return false;
    }

    /**
     * Columnas a partir de la fila de títulos.
     * @return array{fila_inicio:int,col_codigo:int,col_nombre:int,col_fabricante:?int,col_precio:int,col_moneda:?int,col_barcode:null,col_cantidad:null}|null
     */
    public static function columnas(array $filas): ?array
    {
        foreach (array_slice($filas, 0, 10, true) as $i => $f) {
            $t = array_map(fn ($v) => mb_strtolower(trim((string) $v)), $f);
            $cod = array_search('codigo', $t, true);
            $desc = array_search('descripcion', $t, true);
            $lista = array_search('pr.lista', $t, true);
            if ($cod === false || $desc === false || $lista === false) continue;
            $moneda = array_search('moneda', $t, true);
            // en BAHCO hay una columna sin título entre la descripción y el precio: el código de fabricante
            $fab = null;
            for ($c = $desc + 1; $c < $lista; $c++) if ($t[$c] === '') { $fab = $c; break; }
            return [
                'fila_inicio' => $i + 2, 'col_codigo' => $cod, 'col_nombre' => $desc, 'col_fabricante' => $fab,
                'col_precio' => $lista, 'col_moneda' => $moneda === false ? null : $moneda,
                'col_barcode' => null, 'col_cantidad' => null,
            ];
        }
        return null;
    }

    /** El export trae números de máquina ("9399.0921"): "350.125" es 350,125 y no 350.125 como en una lista escrita a mano. */
    public static function precio(?string $v): ?float
    {
        $v = trim((string) $v);
        return is_numeric($v) ? (float) $v : Precios::numero($v);
    }

    /** "Pesos" -> ARS, "Dolar" -> USD, "EUROS" -> EUR (vienen con espacios). */
    public static function moneda(?string $m): string
    {
        $m = mb_strtolower(trim((string) $m));
        return match (true) {
            str_starts_with($m, 'dol'), str_starts_with($m, 'u$'), $m === 'usd' => 'USD',
            str_starts_with($m, 'eur') => 'EUR',
            default => 'ARS',
        };
    }

    /** Marca de la lista según el nombre del archivo: "LISTA-BAHCO-NOV26.xlsx" -> "BAHCO"; la general no tiene. */
    public static function marcaArchivo(string $nombre): ?string
    {
        $n = strtoupper(pathinfo($nombre, PATHINFO_FILENAME));
        $partes = array_filter(preg_split('/[^A-Z]+/', $n), fn ($p) => strlen($p) >= 3
            && !in_array($p, ['LISTA', 'PRECIOS', 'GENERAL', 'NOV', 'DIC', 'ENE', 'FEB', 'MAR', 'ABR', 'MAY', 'JUN', 'JUL', 'AGO', 'SEP', 'OCT', 'BEBER'], true));
        return $partes ? implode(' ', $partes) : null;
    }

    /**
     * Renglones para Procesador::armarCarga (sin convertir moneda: eso lo hace el que llama).
     * @param array $cols lo que devuelve columnas() (col_precio puede cambiarlo el usuario, ej. Pr.Neto)
     */
    public static function renglones(array $filas, array $cols): array
    {
        $celda = fn (array $f, $c) => $c === null ? null : (isset($f[$c]) ? trim((string) $f[$c]) : null);
        $rubro = null;
        $out = [];
        foreach ($filas as $i => $f) {
            if ($i < (int) $cols['fila_inicio'] - 2) continue;
            $a = trim((string) ($f[0] ?? ''));
            if (str_starts_with($a, '---')) {
                $rubro = CodigosDescripcion::arreglar(trim(preg_replace('/^\s*rubro:\s*/i', '', (string) ($f[1] ?? '')))) ?: null;
                continue;
            }
            // un producto es una fila cuyo código (columna A) es un número
            $codigo = $celda($f, $cols['col_codigo']);
            if ($codigo === null || !preg_match('/^\d+$/', $codigo)) continue;

            $desc = (string) $celda($f, $cols['col_nombre']);
            $out[] = [
                'fila'       => $i + 1,
                'barcode'    => null,
                'codigo'     => $codigo,
                'nombre'     => CodigosDescripcion::limpiar($desc),
                'descripcion'=> $desc,
                'fabricante' => $celda($f, $cols['col_fabricante'] ?? null) ?: null,
                'rubro'      => $rubro,
                'moneda'     => self::moneda($celda($f, $cols['col_moneda'] ?? null)),
                'precio'     => self::precio($celda($f, $cols['col_precio'])),
                'cantidad'   => null,
                'unidad'     => null,
                'extras'     => [],
            ];
        }
        return $out;
    }
}
