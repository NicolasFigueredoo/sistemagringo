<?php

namespace App\Services;

use RuntimeException;

/**
 * Lee una lista de precios (Excel, CSV o PDF de texto) y la devuelve como filas de celdas.
 * Sin IA: Excel con PhpSpreadsheet, PDF con pdftotext (poppler-utils).
 */
class LectorLista
{
    /** Columnas ya conocidas cuando el lector "por renglón" (o la IA) armó las filas él mismo. */
    public static ?array $columnasSugeridas = null;

    /** Datos que el lector deduce de la lista: precios_con_iva, columna_costo, ia (bool), aviso. */
    public static array $sugerencias = [];

    /** Unidades de venta típicas que vienen pegadas antes del precio. */
    private const UNIDADES = 'UNIDAD|UNIDADES|UN|UNI|CAJA|CJ|PAQUETE|PAQ|BOLSA|ROLLO|CIEN|MIL|DOCENA|DOC|PAR|JUEGO|JGO|BLISTER|KG|KILO|METRO|MTS|MT|LITRO|LT|PACK|DISPLAY|TIRA|SET|KIT|BIDON|BALDE';

    /** Sube la versión cuando cambia la forma de leer, así no se usan lecturas viejas guardadas. */
    private const VERSION_CACHE = 5;

    /**
     * El resultado queda guardado por contenido del archivo: la vista previa y después la carga (o volver a subir
     * la misma lista) no leen dos veces ni pagan la IA dos veces.
     * @return array<int,array<int,string>> filas (índice 0) de celdas (índice 0 = columna A)
     */
    public static function leer(string $ruta, ?string $extension = null): array
    {
        $ext = strtolower($extension ?? pathinfo($ruta, PATHINFO_EXTENSION));
        self::$columnasSugeridas = null;
        self::$sugerencias = [];

        $leer = fn () => match ($ext) {
            'xlsx', 'xls', 'ods' => self::excel($ruta),
            'csv', 'txt'         => self::csv($ruta),
            'pdf'                => self::pdf($ruta),
            default => throw new RuntimeException("Formato .$ext no soportado. Usá Excel o PDF."),
        };

        if (!function_exists('cache')) return $leer();

        $guardado = cache()->get(self::clave($ruta, $ext));
        if (is_array($guardado)) {
            self::$columnasSugeridas = $guardado['columnas'];
            self::$sugerencias = $guardado['sugerencias'];
            return $guardado['filas'];
        }
        $filas = $leer();
        // si la IA falló (corte de red, etc.) no se guarda: la próxima vez se reintenta
        if ($filas && empty(self::$sugerencias['aviso'])) self::guardar($ruta, $ext, $filas, now()->addDays(7));
        return $filas;
    }

    public static function yaLeida(string $ruta, string $ext): bool
    {
        return cache()->has(self::clave($ruta, strtolower($ext)));
    }

    /** Termina la lectura del PDF hecha con IA de a tramos (pantalla de carga): $r = LectorListaIA::aFilas(). */
    public static function guardarIA(string $ruta, array $r): array
    {
        self::$columnasSugeridas = $r['columnas'];
        self::$sugerencias = ['ia' => true, 'precios_con_iva' => $r['precios_con_iva'], 'columna_costo' => $r['columna_costo']];
        self::guardar($ruta, 'pdf', $r['filas'], now()->addDays(7));
        return $r['filas'];
    }

    /** La IA falló a mitad de la lectura de a tramos: se lee sin IA y se guarda un rato, para que la carga use lo mismo. */
    public static function leerSinIA(string $ruta, string $aviso): array
    {
        self::$columnasSugeridas = null;
        self::$sugerencias = [];
        $filas = self::pdf($ruta, false);
        self::$sugerencias['aviso'] = $aviso;
        if ($filas) self::guardar($ruta, 'pdf', $filas, now()->addHours(3));
        return $filas;
    }

    private static function guardar(string $ruta, string $ext, array $filas, \DateTimeInterface $hasta): void
    {
        cache()->put(self::clave($ruta, $ext), ['filas' => $filas, 'columnas' => self::$columnasSugeridas, 'sugerencias' => self::$sugerencias], $hasta);
    }

    private static function clave(string $ruta, string $ext): string
    {
        return 'lista:' . self::VERSION_CACHE . ':' . $ext . ':' . sha1_file($ruta);
    }

    private static function excel(string $ruta): array
    {
        // listas de 30 MB: solo valores (sin formatos ni estilos) y sin celdas vacías
        @ini_set('memory_limit', '2048M');
        @set_time_limit(0);
        $lector = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($ruta);
        $lector->setReadDataOnly(true);
        $lector->setReadEmptyCells(false);
        $libro = $lector->load($ruta);
        $hoja = $libro->getActiveSheet();
        // formatData=false: valores crudos (los EAN no salen como 7.79E+12)
        $filas = $hoja->toArray(null, true, false, false);
        $libro->disconnectWorksheets();
        unset($libro, $hoja);
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

    private static function pdf(string $ruta, bool $conIA = true): array
    {
        $txt = self::textoPdf($ruta);

        // Con IA: entiende productos en varios renglones, varias columnas de precio, rubros, etc.
        if ($conIA && class_exists(LectorListaIA::class) && function_exists('config') && LectorListaIA::disponible()) {
            try {
                @set_time_limit(0);
                $r = LectorListaIA::desdeConfig()->leer($txt, self::textoControl($ruta));
                if ($r['filas']) {
                    self::$columnasSugeridas = $r['columnas'];
                    self::$sugerencias = ['ia' => true, 'precios_con_iva' => $r['precios_con_iva'], 'columna_costo' => $r['columna_costo']];
                    return $r['filas'];
                }
                self::$sugerencias['aviso'] = 'La IA no encontró productos; se leyó sin IA.';
            } catch (\Throwable $e) {
                if (function_exists('report')) report($e);
                self::$sugerencias['aviso'] = 'No se pudo leer con IA (' . $e->getMessage() . '); se leyó sin IA, revisá los renglones.';
            }
        }

        $lineas = array_values(array_filter(preg_split('/\r\n|\n|\r/', $txt), fn ($l) => trim($l) !== ''));

        // 1) La mayoría de las listas: "CODIGO  DESCRIPCION  [UNIDAD]  $ PRECIO" en cada renglón.
        //    Se lee renglón por renglón, así no importa que cada página tenga otra alineación.
        $filas = self::porRenglon($lineas);
        // renglones que terminan en un precio: si la mayoría se leyó por renglón, usamos ese método
        $conPrecio = count(array_filter($lineas, fn ($l) => preg_match('/\$?\s*\d[\d.,]*\s*\**\s*$/', $l)));
        if (count($filas) >= 5 || (count($filas) >= 1 && count($filas) >= $conPrecio * 0.5)) {
            self::$columnasSugeridas = ['fila_inicio' => 1, 'col_barcode' => 0, 'col_codigo' => 1, 'col_nombre' => 2, 'col_precio' => 4, 'col_cantidad' => null];
            return $filas;
        }

        // 2) Tabla con columnas fijas (por posición)
        return self::limpiar(self::cortarPorPosicion($lineas));
    }

    /**
     * Texto del PDF en el orden en que el PDF guarda cada producto (pdftotext -raw), para controlar los precios de la IA:
     * según la versión de pdftotext, -layout a veces pega los importes de un producto en el renglón de otro.
     */
    public static function textoControl(string $ruta): ?string
    {
        try {
            return self::textoPdf($ruta, true);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Texto del PDF con pdftotext -layout, o -raw con $crudo (páginas separadas por \f). */
    public static function textoPdf(string $ruta, bool $crudo = false): string
    {
        $bin = function_exists('config') ? (config('odoo.pdftotext') ?: 'pdftotext') : 'pdftotext';
        $bin = trim($bin, " \t\"'");
        $salida = tempnam(sys_get_temp_dir(), 'lista');

        // si es una ruta completa, tiene que existir
        if (preg_match('#[\\/]#', $bin) && !is_file($bin)) {
            throw new RuntimeException("No existe el archivo pdftotext: $bin. Revisá PDFTOTEXT_PATH en el .env.");
        }

        [$codigo, $err] = self::ejecutar([$bin, $crudo ? '-raw' : '-layout', '-enc', 'UTF-8', $ruta, $salida]);
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
        return $txt;
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
        // "1.234,56" "1234,56" "1234.56" "1,234.56"
        $precio = '\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?|\d{1,3}(?:,\d{3})+\.\d{1,2}|\d+(?:,\d{1,2})?|\d+\.\d{1,2}';
        // lo que a veces viene después del precio: marcas, "c/u", "+IVA", la alícuota "21%"
        $fin = '(?:\s*(?i:\*+|NUEVO!?|OFERTA!?|NEW!?|C\/U|\+\s*IVA|\d{1,2}(?:[.,]\d{1,2})?\s*%))*\s*$/u';
        $unidad = '(?:\s{2,}(?<unidad>(?i:' . $u . ')\.?|\d{1,5}))?';
        $cola = $unidad . '\s+(?:U\$S|USD|\$)?\s*(?<precio>' . $precio . ')' . $fin;
        $regs = [
            // A) código separado de la descripción por 2+ espacios: "SI 140   SOLDADORA INVERTER ..."
            '/^\s*(?<codigo>[A-Z0-9][A-Z0-9.\/ -]{0,19}?)\s{2,}(?<desc>\S.*?)' . $cola,
            // B) código pegado con 1 espacio: "26030 ABRAZADERA DE ACERO ..." (tiene número o es tipo "AD-CDA")
            '/^\s*(?<codigo>[^\s$]*\d[^\s$]*|[A-Z]{1,6}-[A-Z0-9-]{1,10})\s+(?<desc>.+?)' . $cola,
            // C) sin código: "TORNILLO AUTOPERFORANTE 6X35   $ 1.250,00". El precio tiene que tener
            //    $ o decimales/miles, así "Página 1 de 3" no se toma como producto
            '/^\s*(?<codigo>)(?<desc>\pL.*?)' . $unidad
                . '\s+(?:(?:U\$S|USD|\$)\s*(?<precio>' . $precio . ')|(?<precio2>\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{1,2})?|\d+[.,]\d{2}))' . $fin,
        ];

        $filas = [];
        foreach ($lineas as $l) {
            $l = str_replace("\f", '', $l);
            $m = null;
            foreach ($regs as $re) {
                if (preg_match($re, $l, $mm2)) { $m = $mm2; break; }
            }
            if (!$m) continue;
            if (($m['precio'] ?? '') === '') $m['precio'] = $m['precio2'] ?? '';
            $desc =trim(preg_replace('/\s{2,}/', ' ', $m['desc']));
            // si la regla A tomó "118003 ARCOMETRO" como código y "UNIDAD" como descripción, probar la B
            if (preg_match('/^(?:' . $u . ')\.?$/i', $desc) && preg_match($regs[1], $l, $mb)) {
                $m = $mb;
                $desc = trim(preg_replace('/\s{2,}/', ' ', $m['desc']));
            }
            // otras columnas de precio pegadas al final: "108G / 70ML 18518.70 11507.35"
            $desc = trim(preg_replace('/(?:\s+\$?\s*\d{1,3}(?:[.,]?\d{3})*[.,]\d{2})+$/u', '', $desc));
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
            // Excel a veces trae cientos de columnas vacías a la derecha
            while ($f && end($f) === '') array_pop($f);
            $out[] = array_values($f);
        }
        return $out;
    }

    /**
     * Adivina qué columna es cada cosa.
     * @return array{fila_inicio:int,col_barcode:?int,col_codigo:?int,col_nombre:?int,col_precio:?int,col_cantidad:?int}
     */
    public static function detectarColumnas(array $filas): array
    {
        if (self::$columnasSugeridas) return self::$columnasSugeridas;

        $r = ['fila_inicio' => 1, 'col_barcode' => null, 'col_codigo' => null, 'col_nombre' => null, 'col_precio' => null, 'col_cantidad' => null];

        // 1) por encabezado, en las primeras 15 filas
        foreach (array_slice($filas, 0, 15, true) as $i => $fila) {
            $hits = 0;
            $tmp = $r;
            foreach ($fila as $c => $celda) {
                $t = Matcher::normalizar($celda);
                if ($t === '') continue;
                if ($tmp['col_barcode'] === null && preg_match('/(barra|ean|barcode|cod.*bar)/', $t)) { $tmp['col_barcode'] = $c; $hits++; }
                elseif ($tmp['col_precio'] === null && preg_match('/(precio|costo|importe|p unit|unitario|valor|lista)/', $t)) { $tmp['col_precio'] = $c; $hits++; }
                elseif ($tmp['col_cantidad'] === null && preg_match('/^(cant|cantidad|cant\.?|unidades|uds|qty)$/', $t)) { $tmp['col_cantidad'] = $c; }
                elseif ($tmp['col_nombre'] === null && preg_match('/(desc|nombre|articulo|producto|detalle|denominacion)/', $t)) { $tmp['col_nombre'] = $c; $hits++; }
                elseif ($tmp['col_codigo'] === null && preg_match('/(cod|art|sku|ref)/', $t)) { $tmp['col_codigo'] = $c; $hits++; }
            }
            // si hay "Precio lista" y "Precio costo", el costo es el que ya tiene aplicado el descuento
            foreach ($fila as $c => $celda) {
                if ($tmp['col_precio'] !== null && preg_match('/costo/', Matcher::normalizar($celda))) { $tmp['col_precio'] = $c; break; }
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