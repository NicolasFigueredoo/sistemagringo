<?php

namespace App\Services;

/**
 * Saca los códigos de fabricante escritos dentro de la descripción (listas Sysvent de Angel M. Beber).
 *
 *   "8072 C LLAVE AJUSTABLE CROMADA"          -> 8072C           (código partido por un espacio)
 *   "AJUSTABLE 8072A IP 10\" BAHCO"           -> 8072A, 8072AIP
 *   "K8901Z 1.3/8 BOCALLAVE 3/4 IMPACTO"      -> K8901Z, K8901Z1.3/8 (la medida es parte del código)
 *   "CANDADO*S742-043*LATON*40MM*ARO"         -> S742043
 *   "PU 1.3/8 /1952Z-1.3/8"                   -> 1952Z1.3/8
 *   "AEROSOL VASEL HIDROR.X300ML 367260"      -> 367260
 *
 * Descarta medidas y especificaciones: 10", 6MM, 1/4, 450W, 18V, X300ML, 75X457, GR100, 40-200NM.
 * Sin dependencias de Laravel.
 */
class CodigosDescripcion
{
    /** Palabras que nunca son código ni sufijo de código. */
    private const NO_SUFIJO = ['X', 'Y', 'DE', 'P', 'C/', 'EX', 'A', 'MM', 'CM', 'MT', 'MTS', 'N', 'NO', 'SIN', 'CON', 'PARA', 'LA', 'EL', 'Y/O', 'O', 'E', 'EN', 'DISC'];
    /** Sufijos que en Bahco sí van pegados al código: 8072 C, 141 A, 8072A IP, 2101G-180 IP */
    private const SUFIJOS = '/^(?:[A-Z]|IP|HD|HS|HP|RN|ACS|EV|MP|SL|SB)$/';

    /** Corrige los caracteres mal codificados del export de Sysvent. */
    public static function arreglar(string $s): string
    {
        return trim(strtr($s, ['¥' => 'Ñ', '¤' => 'Ñ', 'ø' => 'º', '¾' => 'º']));
    }

    /** Descripción para mostrar y comparar: sin asteriscos ni basura del final ("****DISC****", "///"). */
    public static function limpiar(string $desc): string
    {
        $s = self::arreglar($desc);
        $s = str_replace('*', ' ', $s);
        $s = preg_replace('~\s*(/{2,}|={2,}|-{3,}).*$~', '', $s) ?: $s;
        $s = preg_replace('~\b(DISC|DISCONT\w*|DISCTONT\w*|NO SE FABRICA MAS|SIN REEMPLAZO)\b.*$~i', '', $s) ?: $s;
        return trim(preg_replace('/\s+/', ' ', $s), " \t/=-.");
    }

    /**
     * Posibles códigos, del más probable al menos probable.
     * @return array<int,array{codigo:string,clave:string,fuerte:bool}>
     */
    public static function candidatos(?string $desc, ?string $columnaCodigo = null): array
    {
        $out = [];
        $agregar = function (string $c, bool $fuerte) use (&$out) {
            $c = trim($c, " .-/=");
            $k = Matcher::codigoClave($c);
            if (!$k || strlen($k) < 3 || isset($out[$k])) return;
            $out[$k] = ['codigo' => $c, 'clave' => $k, 'fuerte' => $fuerte];
        };

        // 1. la columna de código de fabricante (en Bahco viene en ~2% de los renglones)
        if ($columnaCodigo !== null && trim($columnaCodigo) !== '') {
            $agregar($columnaCodigo, true);
            // "8072A IP" -> también "8072A"
            $partes = preg_split('/\s+/', trim($columnaCodigo));
            if (count($partes) > 1 && self::esCodigo($partes[0])) $agregar($partes[0], true);
        }

        $s = mb_strtoupper(self::arreglar((string) $desc), 'UTF-8');
        // "1949Z-1-1.1/8LLAVEABIERTA" -> el código queda pegado a la palabra siguiente
        // (solo si la palabra termina ahí: "7409TORX-T45" es un código entero)
        $s = preg_replace('~([\d/])([A-ZÑ]{4,})(?![\w-])~u', '$1 $2', $s);
        // "HIDROR.X300ML", "DINAM.#1/2", "#1/4,PUNTAS": punto o coma entre palabras separa (en "1.3/8" no)
        $s = preg_replace('~(?<=[A-ZÑ"])[.,](?=[A-ZÑ#\d])|[.,](?=[A-ZÑ#])~u', ' ', $s);
        $palabras = array_values(array_filter(
            array_map(fn ($w) => trim($w, " .,;:-/=()#\"'"), preg_split('~[\s*=()]+|/{2,}~u', $s)),
            fn ($w) => $w !== ''
        ));

        foreach ($palabras as $i => $w) {
            $sig = $palabras[$i + 1] ?? null;
            $codigo = self::esCodigo($w);
            // un número corto ("625", "479-24") solo cuenta si abre la descripción; en el medio suele ser cantidad
            // "8070 C-2 QUIJADA": el número sigue en la palabra de al lado, solo vale el código entero
            $continua = $codigo === 'debil' && $sig !== null && preg_match('/^[A-Z]{1,2}-\w{1,3}$/', $sig);
            if ($continua) $agregar($w . $sig, true);
            if (!$continua && ($codigo === 'fuerte' || ($codigo && $i === 0))) {
                $agregar($w, $codigo === 'fuerte');
                if (preg_match('/^P-(\d.{3,})$/', $w, $m)) $agregar($m[1], false);   // blister Bahco "P-3165-25": el suelto es otro renglón
            }
            if ($sig === null) continue;

            // "8072 C", "8072A IP", "141 A": número o código + sufijo corto ("6 A 26" es un rango, no)
            $tras = $palabras[$i + 2] ?? '';
            if (($codigo || preg_match('/^\d{3,}$/', $w)) && preg_match(self::SUFIJOS, $sig)
                && (!in_array($sig, self::NO_SUFIJO, true) || ($sig === 'A' && !preg_match('/^\d/', $tras)))) {
                $agregar($w . $sig, true);
            }
            // "K8901Z 1.3/8", "8900DZ-1 3/16", "111M 8": código con letras + medida = otro código
            if ($codigo && preg_match('/[A-Z]/', $w) && preg_match('~^\d+([./]\d+)*$~', $sig) && $i <= 1) {
                $agregar($w . $sig, true);
            }
            // "PU 23", "PU 1/2", "PCS 3/4-A-RN", "SX 23": serie + medida. Débil: en Odoo casi nunca está escrito así
            // (con fracción no: "PU 1/4" y "PU 14" quedarían iguales)
            if ($i === 0 && preg_match('/^[A-Z]{1,4}$/', $w) && preg_match('~^\d+([.-]\w+)*$~', $sig)) {
                $agregar($w . $sig, false);
            }
        }
        return array_values($out);
    }

    /**
     * 'fuerte': tiene letras y números ("8072A", "S742-043", "1952M-50") o es un número largo ("367260").
     * 'debil': número de 3 dígitos o con un guion ("625", "479-24"): sirve si coincide, pero puede ser casualidad.
     * false: no es código (palabra, medida, cantidad, fracción).
     */
    public static function esCodigo(string $w): string|false
    {
        $w = trim($w, " .-/=");
        if ($w === '' || !preg_match('/\d/', $w) || mb_strlen($w) > 25) return false;
        if (self::esMedida($w)) return false;
        $k = Matcher::codigoClave($w);
        if (!$k || strlen($k) < 3) return false;

        $letras = preg_match('/[A-Z]/', $k);
        // "PU1/4": serie + fracción, sin la barra se confunde con "PU14"
        if (preg_match('~^[A-Z]{1,4}\d+/\d+$~', $w)) return 'debil';
        if ($letras) {
            // al menos 2 dígitos, o 1 dígito con 2+ letras ("63C-T8", "BP815S", "SL25")
            return preg_match('/\d.*\d|[A-Z].*[A-Z]/', $k) && strlen($k) >= 3 ? 'fuerte' : false;
        }
        if (strlen($k) >= 5) return 'fuerte';
        if (strlen($k) === 4) return preg_match('/[-\/.]/', $w) ? 'fuerte' : 'debil';   // "1446" puede ser un año, una medida...
        return preg_match('/^\d+-\d+/', $w) ? 'debil' : (strlen($k) === 3 ? 'debil' : false);
    }

    /** Medidas, cantidades y especificaciones que nunca son código. */
    public static function esMedida(string $w): bool
    {
        $w = mb_strtoupper(trim($w), 'UTF-8');
        $num = '\d+(?:[.,]\d+)?';
        return (bool) (
            preg_match("~^#?{$num}(\"|''|'|´|º|MM|CM|MTS?|KG|GRS?|G|GS|LTS?|L|ML|CC|W|KW|V|VOL|HP|RPM|REV|TN|NM|PZS?|PZAS?|PCS|U|UN|UNID|UDS|AH|BAR|PSI|LBS?|OZ|DIENTES|DTS?|SEG|HZ|KHZ)\.?$~", $w)    // 10" 6MM 450W 18V 2TN
            // "10M" "5A" "2T" son medidas; "111M" "8072A" "1RM" son códigos Bahco
            || preg_match('~^\d{1,2}(M|A|T)$~', $w)
            || preg_match("~^X\s?{$num}[A-Z\"]*$~", $w)                   // X300ML X10
            || preg_match("~^{$num}X{$num}(X{$num})?[A-Z\"]*$~", $w)       // 75X457 115X1.6
            || preg_match('~^\d+(\.\d+)?/\d+"?$~', $w)                     // 1/4 1.1/8 3/4"
            || preg_match('~^GR?\d+$~', $w)                                // GR100 G80 (granos de lija)
            || preg_match("~^{$num}-{$num}(NM|MM|V|W|KG|TN|\")$~", $w)     // 40-200NM 0-42MM
            || preg_match("~^{$num}[.,]\d+$~", $w)                         // 1.5 3,25
            || preg_match('~^\d{1,2}$~', $w)                               // 10 (cantidad / medida)
            || preg_match('~^[#Nº°]\d+$~u', $w)                            // #3 Nº8
            || preg_match('~^(PH|PZ|TX|T|M|R|E|HEX|SW)\d{1,2}$~', $w)          // PH2 PZ1 T20 M8: puntas y roscas
            || preg_match('~^[\d./]+(-[\d./]+)+$~', $w) && str_contains($w, '/')   // 1/4-1/2 3/8-7/16: rangos
        );
    }
}
