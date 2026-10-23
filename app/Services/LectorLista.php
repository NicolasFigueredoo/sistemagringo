<?php

namespace App\Services;

use RuntimeException;

/**
 * Lee una lista de precios (Excel, CSV o PDF de texto) y la devuelve como filas de celdas.
 * Sin IA: Excel con PhpSpreadsheet, PDF con pdftotext (poppler-utils).
 */
class LectorLista
{
    /** Columnas ya conocidas cuando el lector "por renglón" armó las filas él mismo. */
    public static ?array $columnasSugeridas = null;

    /** Unidades de venta típicas que vienen pegadas antes del precio. */
    private const UNIDADES = 'UNIDAD|UNIDADES|UN|UNI|CAJA|CJ|PAQUETE|PAQ|BOLSA|ROLLO|CIEN|MIL|DOCENA|DOC|PAR|JUEGO|JGO|BLISTER|KG|KILO|METRO|MTS|MT|LITRO|LT|PACK|DISPLAY|TIRA|SET|KIT|BIDON|BALDE';

    /** @return array<int,array<int,string>> filas (índice 0) de celdas (índice 0 = columna A) */
    public static function leer(string $ruta, ?string $extension = null): array
    {
        $ext = strtolower($extension ?? pathinfo($ruta, PATHINFO_EXTENSION));
        self::$columnasSugeridas = null;

        return match ($ext) {
            'xlsx', 'xls', 'ods' => self::excel($ruta),
            'csv', 'txt'         => self::csv($ruta),
            'pdf'                => self::pdf($ruta),
            default => throw new RuntimeException("Formato .$ext no soportado. Usá Excel o PDF."),
        };
    }

    private static function excel(string $ruta): array
    {
        $libro = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta);
        $hoja = $libro->getActiveSheet();
        // formatData=false: valores crudos (los EAN no salen como 7.79E+12)
        $filas = $hoja->toArray(null, true, false, false);
        return self::limpiar($filas);
    }

    private static function csv(string $ruta): array
    {
        $txt = file_get_contents($ruta);
        $sep = substr_count($txt, ';') > substr_count($txt, ',') ? ';' : ',';
        $filas = [];
        foreach (preg_split('/\r\n|\n|\r/', $txt) as $linea) {
            $filas[] = str_getcsv($linea, $sep);
        }
        return self::limpiar($filas);
    }

    private static function pdf(string $ruta): array
    {
        $bin = function_exists('config') ? (config('odoo.pdftotext') ?: 'pdftotext') : 'pdftotext';
        $bin = trim($bin, " \t\"'");
        $salida = tempnam(sys_get_temp_dir(), 'lista');

        // si es una ruta completa, tiene que existir
        if (preg_match('#[\\/]#', $bin) && !is_file($bin)) {
            throw new RuntimeException("No existe el archivo pdftotext: $bin. Revisá PDFTOTEXT_PATH en el .env.");
        }

        [$codigo, $err] = self::ejecutar([$bin, '-layout', '-enc', 'UTF-8', $ruta, $salida]);
        if ($codigo !== 0 || !is_file($salida)) {
            @unlink($salida);
            $pista = match (true) {
                $codigo === -1073741515 || $codigo === 3221225781 => ' (faltan DLLs: el .exe no arranca, instalá Visual C++ Redistributable x64)',
                $codigo === -1073741701 || $codigo === 3221225595 => ' (el .exe no es compatible con este Windows)',
                $codigo === 1 => ' (no pudo abrir el PDF)',
                $codigo === 3 => ' (error de permisos sobre el PDF)',
                default => '',
            };
            throw new RuntimeException("No se pudo leer el PDF con pdftotext (código $codigo$pista). " . trim($err) . " | exe: $bin");
        }
        $txt = file_get_contents($salida);
        @unlink($salida);

        if (strlen(trim($txt)) < 20) {
            throw new RuntimeException('El PDF parece una foto o un escaneo. Pedile al proveedor la lista en Excel.');
        }

        $lineas = array_values(array_filter(preg_split('/\r\n|\n|\r/', $txt), fn ($l) => trim($l) !== ''));

        // 1) La mayoría de las listas: "CODIGO  DESCRIPCION  [UNIDAD]  $ PRECIO" en cada renglón.
        //    Se lee renglón por renglón, así no importa que cada página tenga otra alineación.
        $filas = self::porRenglon($lineas);
        // renglones que terminan en un precio: si la mayoría se leyó por renglón, usamos ese método
        $conPrecio = count(array_filter($lineas, fn ($l) => preg_match('/\$?\s*\d[\d.,]*\s*\**\s*$/', $l)));
        if (count($filas) >= 5 || (count($filas) >= 1 && count($filas) >= $conPrecio * 0.5)) {
            self::$columnasSugeridas = ['fila_inicio' => 1, 'col_barcode' => 0, 'col_codigo' => 1, 'col_nombre' => 2, 'col_precio' => 4];
            return $filas;
        }

        // 2) Tabla con columnas fijas (por posición)
        return self::limpiar(self::cortarPorPosicion($lineas));
    }

    /**
     * Ejecuta un programa externo. Primero con proc_open (sin consola);
     * si eso no está disponible (Windows/XAMPP con proc_open bloqueado), con exec + comillas de cmd.
     * @return array{0:int,1:string} [código de salida, mensaje de error]
     */
    private static function ejecutar(array $args): array
    {
        if (function_exists('proc_open')) {
            $proc = @proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (is_resource($proc)) {
                stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                return [proc_close($proc), (string) $err];
            }
        }

        $partes = array_map(fn ($a) => '"' . str_replace('"', '\\"', $a) . '"', $args);
        $cmd = implode(' ', $partes) . ' 2>&1';
        if (DIRECTORY_SEPARATOR === '\\') {
            $cmd = '"' . $cmd . '"';   // cmd.exe saca el primer y último par de comillas
        }
        $out = [];
        $codigo = 1;
        @exec($cmd, $out, $codigo);
        $ultimo = error_get_last()['message'] ?? '';
        return [$codigo, implode(' ', $out) ?: $ultimo];
    }

    /**
     * Devuelve filas normalizadas: [barcode, codigo, descripcion, unidad, precio].
     * Ignora títulos, encabezados y renglones sin precio.
     */
    private static function porRenglon(array $lineas): array
    {
        $u = self::UNIDADES;
        $cola = '(?:\s{2,}(?<unidad>(?:' . $u . ')\.?|\d{1,5}))?'
            . '\s+\$?\s*(?<precio>\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?|\d+(?:,\d{1,2})?|\d+\.\d{1,2})'
            . '\s*(?:\*+|NUEVO!?|OFERTA!?|NEW!?)?\s*$/u';
        $regs = [
            // A) código separado de la descripción por 2+ espacios: "SI 140   SOLDADORA INVERTER ..."
            '/^\s*(?<codigo>[A-Z0-9][A-Z0-9.\/ -]{0,19}?)\s{2,}(?<desc>\S.*?)' . $cola,
            // B) código pegado con 1 espacio: "26030 ABRAZADERA DE ACERO ..." (tiene número o es tipo "AD-CDA")
            '/^\s*(?<codigo>[^\s$]*\d[^\s$]*|[A-Z]{1,6}-[A-Z0-9-]{1,10})\s+(?<desc>.+?)' . $cola,
        ];

        $filas = [];
        foreach ($lineas as $l) {
            $l = str_replace("\f", '', $l);
            $m = null;
            foreach ($regs as $re) {
                if (preg_match($re, $l, $mm2)) { $m = $mm2; break; }
            }
            if (!$m) continue;
            $desc = trim(preg_replace('/\s{2,}/', ' ', $m['desc']));
            // si la regla A tomó "118003 ARCOMETRO" como código y "UNIDAD" como descripción, probar la B
            if (preg_match('/^(?:' . $u . ')\.?$/i', $desc) && preg_match($regs[1], $l, $mb)) {
                $m = $mb;
                $desc = trim(preg_replace('/\s{2,}/', ' ', $m['desc']));
            }
            if ($desc === '' || !preg_match('/\pL{2,}/u', $desc)) continue;   // tiene que tener palabras
            $codigo = trim($m['codigo']);
            $barcode = preg_match('/^\d{8,14}$/', $codigo) ? $codigo : '';
            // "7790001000017  ESC-01  ESCOBA..." -> EAN + código del proveedor
            if ($barcode && preg_match('/^(\S*\d\S*)\s+(.+)$/u', $desc, $mm) && preg_match('/[A-Za-z-]/', $mm[1]) && preg_match('/^[A-Z0-9.\/-]+$/', $mm[1])) {
                $codigo = $mm[1];
                $desc = $mm[2];
            }
            $filas[] = [$barcode, $codigo, $desc, trim($m['unidad'] ?? ''), $m['precio']];
        }
        return $filas;
    }

    /**
     * Separa las columnas por POSICIÓN (no por espacios), así un renglón sin código de barras
     * no corre las columnas. Una posición es "hueco" si está en blanco en el 85% de los renglones.
     */
    private static function cortarPorPosicion(array $lineas): array
    {
        $lineas = array_map(fn ($l) => rtrim(str_replace("\f", '', $l)), $lineas);
        $ancho = max(array_map('mb_strlen', $lineas ?: ['']));
        $n = count($lineas);
        if ($n === 0) return [];

        $blanco = array_fill(0, $ancho, 0);
        foreach ($lineas as $l) {
            for ($i = 0; $i < $ancho; $i++) {
                $ch = mb_substr($l, $i, 1);
                if ($ch === '' || $ch === ' ') $blanco[$i]++;
            }
        }

        // cortes = centro de cada tramo de >=2 posiciones mayormente en blanco
        $cortes = [];
        $ini = null;
        for ($i = 0; $i <= $ancho; $i++) {
            $esHueco = $i < $ancho && $blanco[$i] >= $n * 0.85;
            if ($esHueco && $ini === null) $ini = $i;
            if (!$esHueco && $ini !== null) {
                if ($i - $ini >= 2 && $ini > 0) $cortes[] = $i;   // la columna nueva arranca en $i
                $ini = null;
            }
        }

        $filas = [];
        foreach ($lineas as $l) {
            $celdas = [];
            $desde = 0;
            foreach (array_merge($cortes, [$ancho]) as $hasta) {
                $celdas[] = trim(mb_substr($l, $desde, $hasta - $desde));
                $desde = $hasta;
            }
            $filas[] = $celdas;
        }
        return $filas;
    }

    private static function limpiar(array $filas): array
    {
        $out = [];
        foreach ($filas as $f) {
            $f = array_map(fn ($c) => trim((string) ($c ?? '')), (array) $f);
            if (implode('', $f) === '') continue;
            $out[] = array_values($f);
        }
        return $out;
    }

    /**
     * Adivina qué columna es cada cosa.
     * @return array{fila_inicio:int,col_barcode:?int,col_codigo:?int,col_nombre:?int,col_precio:?int}
     */
    public static function detectarColumnas(array $filas): array
    {
        if (self::$columnasSugeridas) return self::$columnasSugeridas;

        $r = ['fila_inicio' => 1, 'col_barcode' => null, 'col_codigo' => null, 'col_nombre' => null, 'col_precio' => null];

        // 1) por encabezado, en las primeras 15 filas
        foreach (array_slice($filas, 0, 15, true) as $i => $fila) {
            $hits = 0;
            $tmp = $r;
            foreach ($fila as $c => $celda) {
                $t = Matcher::normalizar($celda);
                if ($t === '') continue;
                if ($tmp['col_barcode'] === null && preg_match('/(barra|ean|barcode|cod.*bar)/', $t)) { $tmp['col_barcode'] = $c; $hits++; }
                elseif ($tmp['col_precio'] === null && preg_match('/(precio|costo|importe|p unit|unitario|valor|lista)/', $t)) { $tmp['col_precio'] = $c; $hits++; }
                elseif ($tmp['col_nombre'] === null && preg_match('/(desc|nombre|articulo|producto|detalle)/', $t)) { $tmp['col_nombre'] = $c; $hits++; }
                elseif ($tmp['col_codigo'] === null && preg_match('/(cod|art|sku|ref)/', $t)) { $tmp['col_codigo'] = $c; $hits++; }
            }
            if ($hits >= 2) {
                $tmp['fila_inicio'] = $i + 2; // datos arrancan debajo del encabezado (1-based)
                return $tmp;
            }
        }

        // 2) sin encabezado: mirar el contenido
        $muestra = array_slice($filas, 0, 50);
        $ncols = max(array_map('count', $muestra ?: [[]]));
        $stats = [];
        for ($c = 0; $c < $ncols; $c++) {
            $ean = $num = $txt = 0; $largo = 0;
            foreach ($muestra as $f) {
                $v = $f[$c] ?? '';
                if ($v === '') continue;
                if (preg_match('/^\d{8,14}$/', preg_replace('/\.0+$/', '', $v))) $ean++;
                elseif (Precios::numero($v) !== null && !preg_match('/[a-z]{2,}/i', $v)) $num++;
                else { $txt++; $largo += strlen($v); }
            }
            $stats[$c] = compact('ean', 'num', 'txt', 'largo');
        }
        $max = fn ($k) => self::columnaMax($stats, $k);
        $r['col_barcode'] = $max('ean');
        $r['col_nombre'] = $max('largo');
        // precio: la última columna mayormente numérica
        for ($c = $ncols - 1; $c >= 0; $c--) {
            if (($stats[$c]['num'] ?? 0) > count($muestra) / 3 && $c !== $r['col_barcode']) { $r['col_precio'] = $c; break; }
        }
        return $r;
    }

    /** columna con el mayor valor de $k (o null si todo es 0) */
    private static function columnaMax(array $stats, string $k): ?int
    {
        $best = null; $bv = 0;
        foreach ($stats as $c => $s) {
            if ($s[$k] > $bv) { $bv = $s[$k]; $best = $c; }
        }
        return $best;
    }
}