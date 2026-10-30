<?php

namespace App\Services;

/**
 * Cuentas de precios. Sin dependencias de Laravel (se puede testear suelto).
 */
class Precios
{
    public const IVA = 21.0;

    /** Costo neto a partir del precio de lista: sin IVA, o con IVA si $agregarIva (para guardar el costo con IVA). */
    public static function costo(float $precioLista, float $descuento = 0, bool $ivaIncluido = false, bool $agregarIva = false): float
    {
        $c = $precioLista;
        if ($ivaIncluido) {
            $c = $c / (1 + self::IVA / 100);
        }
        if ($agregarIva) {
            $c = $c * (1 + self::IVA / 100);
        }
        $c = $c * (1 - $descuento / 100);
        return round($c, 2);
    }

    /** Precio de venta a partir del costo y el % de ganancia. */
    public static function venta(float $costo, float $margen, bool $sumarIva = true, int $redondeo = 0): float
    {
        $p = $costo * (1 + $margen / 100);
        if ($sumarIva) {
            $p = $p * (1 + self::IVA / 100);
        }
        if ($redondeo > 0) {
            $p = ceil($p / $redondeo) * $redondeo;   // redondea siempre para arriba
        }
        return round($p, 2);
    }

    /** % de ganancia que resulta si el usuario escribe el precio a mano. */
    public static function margenDesdePrecio(float $costo, float $precio, bool $sumarIva = true): float
    {
        if ($costo <= 0) return 0;
        $sinIva = $sumarIva ? $precio / (1 + self::IVA / 100) : $precio;
        return round(($sinIva / $costo - 1) * 100, 2);
    }

    /**
     * Descuento encadenado: "50+13" = 50% y sobre lo que queda 13% más = 56,5% en total.
     * Acepta "50", "50+13", "50 + 13 + 5", "50%+13%". Vacío = null.
     */
    public static function descuento($txt): ?float
    {
        if (is_int($txt) || is_float($txt)) return (float) $txt;
        $txt = trim((string) $txt);
        if ($txt === '') return null;
        $queda = 1.0;
        $alguno = false;
        foreach (preg_split('/\s*\+\s*/', $txt) as $parte) {
            $d = self::numero(str_replace('%', '', $parte));
            if ($d === null) continue;
            $queda *= 1 - $d / 100;
            $alguno = true;
        }
        return $alguno ? round((1 - $queda) * 100, 4) : null;
    }

    /** Texto para guardar junto al descuento: solo si es encadenado ("50+13"), si no alcanza con el número. */
    public static function textoDescuento($txt): ?string
    {
        $txt = preg_replace('/\s+/', '', trim((string) $txt));
        return str_contains($txt, '+') ? mb_substr($txt, 0, 50) : null;
    }

    /**
     * Convierte texto a número aceptando formato argentino y gringo:
     * "1.234,56" "1234,56" "1,234.56" "1234.56" "$ 4.109,37" "12.500"
     */
    public static function numero($v): ?float
    {
        if (is_int($v) || is_float($v)) return (float) $v;
        $s = trim((string) $v);
        $s = preg_replace('/[^\d,.\-]/', '', $s);
        if ($s === '' || $s === '-' || !preg_match('/\d/', $s)) return null;

        $coma = strrpos($s, ',');
        $punto = strrpos($s, '.');

        if ($coma !== false && $punto !== false) {
            // el último separador es el decimal
            if ($coma > $punto) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($coma !== false) {
            // "1,234,567" -> miles ; "1234,56" -> decimal
            $s = preg_match('/^-?\d{1,3}(,\d{3}){2,}$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($punto !== false) {
            // "12.500" o "1.234.567" -> miles ; "1234.56" -> decimal
            if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
                $s = str_replace('.', '', $s);
            }
        }
        return is_numeric($s) ? (float) $s : null;
    }
}
