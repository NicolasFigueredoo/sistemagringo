<?php

namespace Tests\Unit;

use App\Services\CodigosDescripcion;
use App\Services\LectorSysvent;
use App\Services\Matcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CodigosDescripcionTest extends TestCase
{
    private static function claves(string $desc, ?string $columna = null): array
    {
        return array_column(CodigosDescripcion::candidatos($desc, $columna), 'clave');
    }

    public static function ejemplos(): array
    {
        return [
            // código partido por un espacio / con sufijo
            ['8072 C LLAVE AJUSTABLE CROMADA', '8072C'],
            ['AJUSTABLE 8072A IP 10" BAHCO', '8072AIP'],
            ['AJUSTABLE 8072A IP 10" BAHCO', '8072A'],
            ['LLAVE CAÑO 141 A BAHCO  320MM', '141A'],
            ['2101G-180 IP ALICATE  C/RESORTE', '2101G180IP'],
            // al principio
            ['479F-32 MARTILLO BOLA 900G.MANGO FIBRA V', '479F32'],
            ['6700SM-6 BOCALLAVE HEXAG 1/4"', '6700SM6'],
            ['S-31100-200H DESTOR.', 'S31100200H'],
            ['1-230-12-2-0 LIMA REDONDA', '12301220'],
            ['63C-T8 PUNTA TORX 1/4"****', '63CT8'],
            ['7409TORX-T45 BOCALLAVE #3/8 TORX', '7409TORXT45'],
            // la medida es parte del código
            ['K8901Z 1.3/8 BOCALLAVE 3/4 IMPACTO', 'K8901Z138'],
            ['8900DZ-1 3/16 BOCALLA. ESTRIADA 3/4*****', '8900DZ1316'],
            ['1949Z-1-1.1/8LLAVEABIERTA****DISC*****', '1949Z1118'],
            ['PU 1.3/8 /1952Z-1.3/8', '1952Z138'],
            ['111M 8=PUA 8COMBINADA ANGULADA****', '111M8'],
            // en el medio o al final
            ['CANDADO*S742-043*LATON*40MM*ARO*LARGO***', 'S742043'],
            ['AEROSOL VASEL HIDROR.X300ML 367260', '367260'],
            ['BANDA LIJA 75X457 GR100 NOR 705883', '705883'],
            ['MANGUERA PARA HIDROLAV PW1550 50969', 'PW1550'],
            ['MANGUERA PARA HIDROLAV PW1550 50969', '50969'],
            ['BCL33AP1K1*KIT*PULIDORA*ORBITAL*ANG*18V*', 'BCL33AP1K1'],
            ['DAAX9 HACHA 230MM 550G DAEWO****DISC**', 'DAAX9'],
            ['1862 JUEGO MECANICA LIGERA//**DISC*****', '1862'],
        ];
    }

    #[DataProvider('ejemplos')]
    public function test_saca_el_codigo(string $desc, string $esperado): void
    {
        $this->assertContains($esperado, self::claves($desc));
    }

    public function test_medidas_y_specs_no_son_codigo(): void
    {
        $todas = array_merge(
            self::claves('AJUSTABLE 8072A IP 10" BAHCO'),
            self::claves('CANDADO*S742-043*LATON*40MM*ARO*LARGO***'),
            self::claves('6700SM-6 BOCALLAVE HEXAG 1/4"'),
            self::claves('KS701E-AR/KS455*CALADORA*450W*V.V*****'),
            self::claves('BCL33AP1K1*KIT*PULIDORA*ORBITAL*ANG*18V*'),
            self::claves('AEROSOL VASEL HIDROR.X300ML 367260'),
            self::claves('BANDA LIJA 75X457 GR100 NOR 705883'),
            self::claves('7455-200 LLAVE DINAM.#1/2 ZAFE 40-200NM'),
            self::claves('3302-150 DESTOR NARANJA PH2'),
            self::claves('S81MIX  SET 1/4-1/2 81 PZ'),
        );
        foreach (['10', '40MM', '14', '450W', '18V', 'X300ML', 'HIDRORX300ML', '75X457', 'GR100', '40200NM', 'DINAM12', 'PH2', '1412'] as $no) {
            $this->assertNotContains($no, $todas, "«{$no}» no es un código");
        }
    }

    public function test_sin_codigo(): void
    {
        $this->assertSame([], self::claves('LLAVE T R.9 11MM'));
        $this->assertSame([], self::claves('REM 6X25 CAJA 250'));
    }

    public function test_serie_con_fraccion_no_se_confunde_con_numero(): void
    {
        // "PU 1/4" sin la barra sería "PU14", que es otra llave
        $this->assertNotContains('PU14', self::claves('PU 1/4'));
        $this->assertNotContains('120', self::claves('P1-20 TIJERA DE PODA PROF'));
    }

    public function test_columna_de_fabricante_primero(): void
    {
        $c = CodigosDescripcion::candidatos('AJUSTABLE 8072A IP 10" BAHCO', '8072A IP');
        $this->assertSame('8072AIP', $c[0]['clave']);
    }

    public function test_limpia_la_descripcion(): void
    {
        $this->assertSame('DAAX9 HACHA 230MM 550G DAEWO', CodigosDescripcion::limpiar('DAAX9 HACHA 230MM 550G DAEWO****DISC**'));
        $this->assertSame('1862 JUEGO MECANICA LIGERA', CodigosDescripcion::limpiar('1862 JUEGO MECANICA LIGERA//**DISC*****'));
        $this->assertSame('LLAVE CAÑO 141 A', CodigosDescripcion::limpiar('LLAVE CA¥O 141 A'));
    }

    public function test_lector_sysvent(): void
    {
        $filas = [
            ['Angel M.BEBER S.A.'], ['03/11/2026'], ['Sysvent: LISTA DE PRECIOS'],
            ['Codigo', 'Descripcion', '', 'Pr.Lista', '%IVA', 'Moneda', 'Ult.Mod.Pr.'],
            ['-----------------', 'Rubro: 003-LLAVES DE CA¥O'],
            ['038004', 'AJUSTABLE 8072A IP 10" BAHCO', '8072A IP', '39577', '21', 'Pesos ', '03/11/2026'],
            ['044200', '130034 REPUESTO TIJERA', '', '350.125', '21', ' Dolar', '06/07/2026'],
        ];
        $this->assertTrue(LectorSysvent::es($filas));
        $cols = LectorSysvent::columnas($filas);
        $this->assertSame(['fila_inicio' => 5, 'col_codigo' => 0, 'col_nombre' => 1, 'col_fabricante' => 2, 'col_precio' => 3, 'col_moneda' => 5], array_intersect_key($cols, array_flip(['fila_inicio', 'col_codigo', 'col_nombre', 'col_fabricante', 'col_precio', 'col_moneda'])));
        $r = LectorSysvent::renglones($filas, $cols);
        $this->assertCount(2, $r);
        $this->assertSame('003-LLAVES DE CAÑO', $r[0]['rubro']);
        $this->assertSame('8072A IP', $r[0]['fabricante']);
        $this->assertSame('ARS', $r[0]['moneda']);
        $this->assertSame('USD', $r[1]['moneda']);
        $this->assertSame(350.125, $r[1]['precio']);   // número de máquina, no "miles"
        $this->assertSame('BAHCO', LectorSysvent::marcaArchivo('LISTA-BAHCO-NOV26.xlsx'));
        $this->assertNull(LectorSysvent::marcaArchivo('LISTA-GENERAL-16-11-26.xlsx'));
    }

    public function test_matcher_sysvent(): void
    {
        $m = new Matcher([
            ['id' => 1, 'nombre' => 'llave ajustable 10" bahco fosfatizada N', 'barcode' => null, 'default_code' => '8072a'],
            ['id' => 2, 'nombre' => 'llave 1/2"combinada bahco pu', 'barcode' => null, 'default_code' => null],
            ['id' => 3, 'nombre' => 'llave 14mm combinada bahco pu 14 N', 'barcode' => null, 'default_code' => null],
            ['id' => 4, 'nombre' => 'cinta metrica 5mts BAHCO mtg-5-19ar', 'barcode' => null, 'default_code' => 'MTG-5-19AR'],
            ['id' => 5, 'nombre' => 'bocallave 5mm largo enc 1/4 IRIMO', 'barcode' => null, 'default_code' => '103051'],
        ], ['c:038011' => 1]);

        $this->assertSame(['codigo', 1], self::r($m->buscarSysvent('038004', 'AJUSTABLE 8072A IP 10" BAHCO', '8072A IP', '002-AJUSTABLES', 'BAHCO')));
        // asociación guardada por código interno de Beber
        $this->assertSame(['mapeo', 1], self::r($m->buscarSysvent('038011', '8072 C LLAVE AJUSTABLE CROMADA', null, '002-AJUSTABLES')));
        // serie + medida: hay que tildarlo, y "PU 1/4" no cae en la de 14mm
        $this->assertSame(['nombre', 2], self::r($m->buscarSysvent('1', 'PU 1/2', null, '007-LLAVES PU', 'BAHCO')));
        $this->assertNotSame(3, $m->buscarSysvent('2', 'PU 1/4', null, '007-LLAVES PU', 'BAHCO')['odoo_product_id']);
        // misma referencia con letra de variante: se confirma a mano
        $this->assertSame(['nombre', 4], self::r($m->buscarSysvent('3', 'MTG-5-19   CINTA METRICA 5MX19MM', null, '111-BAHCO', 'BAHCO')));
        // el código coincide pero no comparte ninguna palabra ni la marca: no es seguro
        $this->assertNotSame('codigo', $m->buscarSysvent('4', '10-30-51 ARCO MONTARAZ ERGO FORCE', null, '111-BAHCO', 'BAHCO')['match']);
    }

    private static function r(array $res): array
    {
        return [$res['match'], $res['odoo_product_id']];
    }
}
