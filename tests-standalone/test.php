<?php
require __DIR__ . '/../app/Services/Precios.php';
require __DIR__ . '/../app/Services/Matcher.php';
require __DIR__ . '/../app/Services/LectorLista.php';

use App\Services\{Precios, Matcher, LectorLista};

$fallas = 0;
function ok($cond, $msg) { global $fallas; echo ($cond ? "  ✓ " : "  ✗ ") . $msg . "\n"; if (!$cond) $fallas++; }

echo "Números:\n";
foreach (['4.109,37' => 4109.37, '12.500' => 12500, '1,234.56' => 1234.56, '1234.56' => 1234.56, '$ 2.180,50' => 2180.5, '1350' => 1350, '0,5' => 0.5, 'abc' => null] as $in => $esp) {
    ok(Precios::numero($in) === ($esp === null ? null : (float) $esp), "'$in' -> " . var_export(Precios::numero($in), true));
}

echo "Precios:\n";
$c = Precios::costo(4109.37, 5);                ok($c == 3903.90, "costo con 5% dto = $c");
$c2 = Precios::costo(1210, 0, true);            ok($c2 == 1000.0, "lista con IVA 1210 -> costo $c2");
$v = Precios::venta(1000, 60, true);            ok($v == 1936.0, "1000 +60% +IVA = $v");
$v2 = Precios::venta(1000, 60, false);          ok($v2 == 1600.0, "1000 +60% sin IVA = $v2");
$v3 = Precios::venta(1000, 61, true, 50);       ok($v3 == 1950.0, "redondeo a 50: $v3");
$mg = Precios::margenDesdePrecio(1000, 2000, true); ok(abs($mg - 65.29) < 0.01, "precio a mano 2000 -> margen $mg%");

echo "Matching:\n";
$catalogo = [
    ['id' => 1, 'nombre' => 'PALO DE ESCOBA MADERA 1.20 MTS', 'barcode' => '7790001000024', 'default_code' => 'P-120'],
    ['id' => 2, 'nombre' => 'Escoba Plástica Reforzada',      'barcode' => null,            'default_code' => null],
    ['id' => 3, 'nombre' => 'PERCHAS AUTOADHESIVAS 18MM X 5U', 'barcode' => null,            'default_code' => null],
    ['id' => 4, 'nombre' => 'PERCHAS AUTOADHESIVAS 35MM X 2U', 'barcode' => null,            'default_code' => null],
    ['id' => 5, 'nombre' => 'TORNILLO AUTOPERFORANTE 6X35',    'barcode' => '7790001000048', 'default_code' => null],
    ['id' => 6, 'nombre' => 'ESCOBILLON INTERIOR',              'barcode' => null,            'default_code' => null],
];
$mt = new Matcher($catalogo, ['c:CIN-48' => 6]);

$r = $mt->buscar('7790001000024', 'PAL-12', 'PALO DE ESCOBA');
ok($r['match'] === 'barcode' && $r['odoo_product_id'] === 1, "barcode exacto -> verde (#1)");

$r = $mt->buscar(null, null, 'ESCOBA PLASTICA REFORZADA');
ok($r['match'] === 'nombre' && $r['odoo_product_id'] === 2, "nombre idéntico sin acento -> amarillo (#2)");

$r = $mt->buscar(null, null, 'ESCOBA');
ok($r['odoo_product_id'] === null, "'ESCOBA' NUNCA se asigna solo (match={$r['match']})");
echo "     candidatos: " . implode(' | ', array_map(fn($c) => "{$c['nombre']} ({$c['score']})", $r['candidatos'])) . "\n";

$r = $mt->buscar(null, null, 'PERCHAS AUTOADHESIVAS 18MM X 5 U');
ok($r['match'] === 'nombre' && $r['odoo_product_id'] === 3, "'18MM X 5 U' = '18MM X 5U' -> amarillo, el de 18mm");

$r = $mt->buscar(null, null, 'PERCHAS AUTOADHESIVAS 25MM X 5 U');
ok($r['odoo_product_id'] === null && !array_filter($r['candidatos'], fn($c) => in_array($c['id'], [3, 4])), "perchas 25mm -> NO sugiere las de 18mm ni 35mm");

$r = $mt->buscar(null, null, 'TORNILLO AUTOPERFORANTE 6 X 35');
ok(($r['candidatos'][0]['id'] ?? $r['odoo_product_id']) === 5, "tornillo 6x35 -> sugiere el 6X35");

$r = $mt->buscar(null, 'CIN-48', 'CINTA EMBALAR');
ok($r['match'] === 'mapeo' && $r['odoo_product_id'] === 6, "asociación guardada -> verde");
$r = (new Matcher($catalogo, ['c:XX-1' => Matcher::IGNORAR]))->buscar('7790001000024', 'XX-1', 'PALO DE ESCOBA');
ok($r['match'] === 'barcode', "el código de barras gana a un 'no hacer nada' viejo");
$r = (new Matcher($catalogo, ['c:XX-1' => Matcher::IGNORAR]))->buscar(null, 'XX-1', 'Escoba Plástica Reforzada');
ok($r['match'] === 'ignorado' && $r['odoo_product_id'] === null, "'no hacer nada' guardado -> ignorado");

$r = $mt->buscar(null, null, 'MARTILLO GALPONERO');
ok($r['match'] === 'ninguno', "sin nada parecido -> gris");

$r = $mt->buscar('7790001000024.0', null, null);
ok($r['match'] === 'barcode', "EAN con '.0' de Excel también matchea");

echo "Por código:\n";
$catCod = [
    ['id' => 10, 'nombre' => 'disco 9 diamantado aliafor',     'barcode' => null,    'default_code' => 'LS-9'],
    ['id' => 11, 'nombre' => 'PINZA 6 BULIT',                  'barcode' => null,    'default_code' => 'PZA P10 PS6'],
    ['id' => 12, 'nombre' => 'alambre galv 16',                'barcode' => null,    'default_code' => '022014'],
    ['id' => 13, 'nombre' => 'pasador doble 100',              'barcode' => '12445', 'default_code' => null],
    ['id' => 14, 'nombre' => 'TUERCA 100',                     'barcode' => null,    'default_code' => '100'],
    ['id' => 15, 'nombre' => 'MECHA WIDIA 8MM',                'barcode' => null,    'default_code' => 'MW-8'],
];
$mc = new Matcher($catCod, [], [['codigo' => 'ABC.990', 'product_id' => 15]]);
$r = $mc->buscar(null, 'ls 9', 'Láser General 9"');
ok($r['match'] === 'codigo' && $r['odoo_product_id'] === 10, "referencia interna sin guión ('ls 9' = 'LS-9') -> verde");
$r = $mc->buscar(null, 'PZA-P10-PS6', 'PINZA');
ok($r['odoo_product_id'] === 11, "referencia con espacios en Odoo y guiones en la lista");
$r = $mc->buscar(null, '22014', 'ALAMBRE');
ok($r['match'] === 'codigo' && $r['odoo_product_id'] === 12, "ceros adelante no importan (22014 = 022014)");
$r = $mc->buscar(null, '12445', 'PASADOR');
ok($r['odoo_product_id'] === 13, "código cargado en el código de barras corto de Odoo");
$r = $mc->buscar(null, '100', 'TUERCA');
ok($r['match'] === 'nombre' && $r['odoo_product_id'] === 14, "código corto ('100') -> hay que confirmarlo");
$r = $mc->buscar(null, 'abc-990', 'MECHA');
ok($r['match'] === 'codigo' && $r['odoo_product_id'] === 15, "código del proveedor cargado en Odoo -> verde");
$r = $mc->buscar(null, 'XX-1', 'MW-8 MECHA WIDIA', ['MW-8']);
ok($r['match'] === 'nombre' && $r['odoo_product_id'] === 15, "segundo código pegado a la descripción -> amarillo");
$r = (new Matcher($catCod, [], [['codigo' => 'ABC.990', 'product_id' => 15]], false))->buscar(null, 'ABC990', 'MECHA');
ok($r['match'] === 'nombre', "código de proveedor sin saber cuál es el proveedor en Odoo -> confirmar");

$mb = new Matcher([['id' => 30, 'nombre' => 'bomba vasser 1/2 hp PERIFERICA N', 'barcode' => '1253', 'default_code' => null]]);
$r = $mb->buscar(null, '1253', 'LLAVE TERMICA BIPOLAR 25A');
ok($r['odoo_product_id'] === null, "código de barras corto de Odoo ('1253') con otro nombre -> no se asocia");
$r = $mb->buscar(null, '1253', 'BOMBA PERIFERICA VASSER 1/2 HP');
ok($r['match'] === 'codigo' && $r['odoo_product_id'] === 30, "código de barras corto con nombre parecido -> verde");

$mp = new Matcher([['id' => 20, 'nombre' => 'POXIPOL gris 108G/70ML 10 MIN', 'barcode' => null, 'default_code' => 'ST00118']]);
$r = $mp->buscar(null, 'ST00118-POXIPOL', 'Met. 10 MIN. 108G / 70ML');
ok($r['match'] === 'codigo' && $r['odoo_product_id'] === 20, "código con la marca pegada ('ST00118-POXIPOL' = 'ST00118') -> verde");

echo "Reajuste sin IVA:\n";
$v4 = Precios::venta(1000, 50, false);          ok($v4 == 1500.0, "costo 1000 + 50% = $v4");

echo "PDF:\n";
$filas = LectorLista::leer(__DIR__ . '/lista_prueba.pdf');
foreach ($filas as $f) echo "     " . json_encode($f, JSON_UNESCAPED_UNICODE) . "\n";
$cols = LectorLista::detectarColumnas($filas);
echo "     columnas: " . json_encode($cols) . "\n";
ok($cols['col_barcode'] === 0 && $cols['col_precio'] === 4 && $cols['col_nombre'] === 2 && $cols['col_codigo'] === 1, "columnas detectadas bien");
$perchas = array_values(array_filter($filas, fn($f) => str_contains(implode(' ', $f), 'PERCHAS')))[0];
ok($perchas[0] === '' && $perchas[1] === 'AFX-P18' && $perchas[4] === '4.109,37', "renglón sin código de barras NO corre las columnas");
$escoba = $filas[0];
ok($escoba[0] === '7790001000017' && $escoba[1] === 'ESC-01', "EAN + código del proveedor separados");

echo "CSV:\n";
$csv = sys_get_temp_dir() . '/l.csv';
file_put_contents($csv, "Codigo;Descripcion;Precio\nA1;Escoba plastica reforzada;1.500,00\n");
$f = LectorLista::leer($csv);
$cc = LectorLista::detectarColumnas($f);
ok($cc['col_precio'] === 2 && $cc['col_nombre'] === 1 && $cc['fila_inicio'] === 2, "CSV con ; detectado: " . json_encode($cc));

echo $fallas ? "\n$fallas FALLAS\n" : "\nTODO OK\n";
