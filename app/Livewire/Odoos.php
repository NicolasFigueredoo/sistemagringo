<?php

namespace App\Livewire;

use App\Jobs\TareaOdoos;
use App\Services\CatalogoLocal;
use App\Services\OdooClient;
use App\Services\Precios;
use App\Support\Instancia;
use App\Support\Progreso;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Odoo viejo → Odoo nuevo: sincronizar todo, comparar categorías y aplicar el % de venta.
 * Lo que tarda corre en segundo plano (TareaOdoos); la tabla sale de las bases locales, así abre al instante.
 */
#[Layout('layouts.app')]
#[Title('Viejo → Nuevo')]
class Odoos extends Component
{
    public string $buscar = '';
    /** todas | ambos | viejo | nuevo */
    public string $filtro = 'todas';
    /** dónde se aplica el %: viejo | nuevo | ambos */
    public string $destino = 'nuevo';
    public int $redondeo = 0;
    /** id corto de la categoría => % escrito */
    public array $pct = [];
    public ?string $mensaje = null;
    public ?string $error = null;

    public function mount(): void
    {
        // arranca con la ganancia ya guardada de cada categoría (primero la del nuevo, que es la que usan las listas)
        foreach ($this->filas() as $f) {
            $m = $f['nuevo']['margen'] ?? $f['viejo']['margen'] ?? null;
            if ($m !== null) $this->pct[$f['id']] = self::texto($m);
        }
    }

    public function sincronizarTodo(): void
    {
        $this->limpiar();
        if (!$this->configurados()) return;
        if (!Progreso::iniciar('sincronizar', 'Sincronización del Odoo viejo al nuevo')) {
            $this->error = 'Ya hay una tarea en curso: esperá a que termine.';
            return;
        }
        self::correr(new TareaOdoos('sincronizar'));
    }

    /**
     * En el hosting (Hostinger) no hay proceso de cola: la tarea corre en este mismo pedido, apenas se le
     * contesta al navegador (litespeed/fastcgi_finish_request), y la pantalla sigue el avance con otros pedidos.
     * En la PC (php -S atiende de a un pedido) va a la cola y se arranca el proceso de fondo.
     */
    private static function correr(TareaOdoos $tarea): void
    {
        if (PHP_SAPI === 'cli-server') {
            dispatch($tarea);
            Progreso::lanzarProceso();
            return;
        }
        dispatch($tarea)->afterResponse();
    }

    /** Aplica el % de una categoría ($id) o de todas las que tienen un % escrito. */
    public function aplicar(?string $id = null): void
    {
        $this->limpiar();
        if (!in_array($this->destino, ['viejo', 'nuevo', 'ambos'], true)) return;
        if (!$this->configurados()) return;

        $porcentajes = [];
        foreach ($this->filas() as $f) {
            if ($id !== null && $f['id'] !== $id) continue;
            $txt = trim((string) ($this->pct[$f['id']] ?? ''));
            if ($txt === '') continue;
            $v = Precios::numero($txt);
            if ($v === null || $v < 0 || $v > 10000) {
                $this->error = "El porcentaje de «{$f['nombre']}» no es válido.";
                return;
            }
            $porcentajes[$f['clave']] = $v;
        }
        if (!$porcentajes) {
            $this->error = $id ? 'Escribí el porcentaje de esa categoría.' : 'Escribí el porcentaje de al menos una categoría.';
            return;
        }

        $destinos = $this->destino === 'ambos' ? ['viejo', 'nuevo'] : [$this->destino];
        $titulo = count($porcentajes) === 1 ? 'Porcentaje de venta en 1 categoría' : 'Porcentaje de venta en ' . count($porcentajes) . ' categorías';
        if (!Progreso::iniciar('porcentaje', $titulo)) {
            $this->error = 'Ya hay una tarea en curso: esperá a que termine.';
            return;
        }
        self::correr(new TareaOdoos('porcentaje', ['porcentajes' => $porcentajes, 'destinos' => $destinos, 'redondeo' => in_array($this->redondeo, [0, 10, 50, 100], true) ? $this->redondeo : 0]));
    }

    /** Vuelve a leer categorías y productos de los dos Odoo (solo lectura, en paralelo). */
    public function actualizarListas(): void
    {
        $this->limpiar();
        if (!$this->configurados()) return;
        @set_time_limit(0);
        try {
            $t = microtime(true);
            [, $prv] = CatalogoLocal::actualizar(OdooClient::desdeArray(Instancia::configSecundario()), 'sqlite_viejo');
            [, $prn] = CatalogoLocal::actualizar(OdooClient::desdeArray(Instancia::configPrincipal()), Instancia::conexionPrincipal());
            $this->mensaje = sprintf('Listas actualizadas en %d s: viejo %s productos, nuevo %s productos.',
                round(microtime(true) - $t), self::n($prv), self::n($prn));
        } catch (\Throwable $e) {
            $this->error = 'No se pudo leer Odoo: ' . $e->getMessage();
        }
    }

    public function cerrarResultado(): void
    {
        Progreso::descartar();
    }

    private function limpiar(): void
    {
        $this->mensaje = $this->error = null;
    }

    private function configurados(): bool
    {
        foreach (['viejo (ODOO_VIEJO_USER / ODOO_VIEJO_API_KEY)' => Instancia::configSecundario(), 'nuevo (ODOO_USER / ODOO_API_KEY)' => Instancia::configPrincipal()] as $n => $c) {
            if (empty($c['user']) || empty($c['api_key'])) {
                $this->error = "Falta el usuario o la API key del Odoo $n en el .env.";
                return false;
            }
        }
        return true;
    }

    /** Categorías de los dos Odoo, unidas por nombre completo. */
    private function filas(): array
    {
        $filas = [];
        foreach (['viejo' => 'sqlite_viejo', 'nuevo' => Instancia::conexionPrincipal()] as $lado => $con) {
            try {
                $db = DB::connection($con);
                $conteo = $db->table('odoo_productos')->selectRaw('categ_id, COUNT(*) as n')->groupBy('categ_id')->pluck('n', 'categ_id');
                $cats = $db->table('categorias')->get(['id', 'nombre', 'margen']);
            } catch (\Throwable) {
                continue;   // base local del nuevo sin crear todavía
            }
            foreach ($cats as $c) {
                $clave = CatalogoLocal::claveCategoria($c->nombre);
                $id = substr(md5($clave), 0, 10);
                $filas[$id] ??= ['id' => $id, 'clave' => $clave, 'nombre' => $c->nombre, 'viejo' => null, 'nuevo' => null];
                $filas[$id][$lado] = ['n' => (int) ($conteo[$c->id] ?? 0), 'margen' => $c->margen];
            }
        }
        uasort($filas, fn ($a, $b) => strnatcasecmp($a['nombre'], $b['nombre']));
        return $filas;
    }

    private static function texto($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
    }

    private static function n(int $v): string
    {
        return number_format($v, 0, ',', '.');
    }

    public function render()
    {
        $todas = $this->filas();
        $stats = [
            'cats_viejo'  => count(array_filter($todas, fn ($f) => $f['viejo'])),
            'cats_nuevo'  => count(array_filter($todas, fn ($f) => $f['nuevo'])),
            'solo_viejo'  => count(array_filter($todas, fn ($f) => $f['viejo'] && !$f['nuevo'])),
            'solo_nuevo'  => count(array_filter($todas, fn ($f) => !$f['viejo'] && $f['nuevo'])),
            'prods_viejo' => array_sum(array_map(fn ($f) => $f['viejo']['n'] ?? 0, $todas)),
            'prods_nuevo' => array_sum(array_map(fn ($f) => $f['nuevo']['n'] ?? 0, $todas)),
        ];

        $q = mb_strtolower(trim($this->buscar));
        $filas = array_filter($todas, fn ($f) => ($q === '' || str_contains(mb_strtolower($f['nombre']), $q)) && match ($this->filtro) {
            'ambos' => $f['viejo'] && $f['nuevo'],
            'viejo' => $f['viejo'] && !$f['nuevo'],
            'nuevo' => !$f['viejo'] && $f['nuevo'],
            default => true,
        });
        $conPct = count(array_filter($todas, fn ($f) => trim((string) ($this->pct[$f['id']] ?? '')) !== ''));

        $tarea = Progreso::actual();
        $enCurso = Progreso::enCurso();
        // en cola hace rato y nadie la tomó: el proceso de fondo no está corriendo
        $esperando = $tarea && $enCurso && $tarea['estado'] === 'en_cola' ? time() - $tarea['inicio'] : 0;
        // sigue en cola: se vuelve a arrancar el proceso (por si el anterior no pudo)
        if ($esperando > 8 && PHP_SAPI === 'cli-server') Progreso::lanzarProceso();
        $sinProceso = $esperando > 75;

        return view('livewire.odoos', compact('filas', 'stats', 'conPct', 'tarea', 'enCurso', 'sinProceso'));
    }
}
