<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente mínimo de Odoo por JSON-RPC (/jsonrpc), con API key.
 * Funciona en Odoo Online 19. Cuando Odoo saque JSON-RPC, se cambia solo esta clase.
 */
class OdooClient
{
    private ?int $uid = null;

    /** Veces que se reintenta un pedido que Odoo frenó (429 = demasiados pedidos) o que no llegó. */
    private const REINTENTOS = 8;

    public function __construct(
        private string $url,
        private string $db,
        private string $user,
        private string $apiKey,
        private $stockLocationId = null,
    ) {
        $url = trim($url, " \t\n\r\0\x0B\"'");
        if ($url === '') {
            throw new RuntimeException("Falta la dirección del Odoo de la base \"$db\": revisá ODOO_URL / ODOO_VIEJO_URL en el .env.");
        }
        // "ferreteria.odoo.com" sin https:// da "URI must include a scheme and host"
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        $this->url = rtrim($url, '/');
    }

    public static function desdeConfig(): self
    {
        return self::desdeArray(config('odoo'));
    }

    /** @param array $c url, db, user, api_key, stock_location_id */
    public static function desdeArray(array $c): self
    {
        return new self((string) $c['url'], (string) $c['db'], (string) $c['user'], (string) $c['api_key'], $c['stock_location_id'] ?? null);
    }

    private ?array $camposTipo = null;

    /** Campos para que un producto nuevo lleve stock: Odoo 18+ usa is_storable, 16-17 detailed_type, antes type. */
    public function camposAlmacenable(): array
    {
        if ($this->camposTipo === null) {
            $f = $this->call('product.product', 'fields_get', [], ['attributes' => ['type']]);
            $this->camposTipo = match (true) {
                isset($f['is_storable'])   => ['type' => 'consu', 'is_storable' => true],
                isset($f['detailed_type']) => ['detailed_type' => 'product'],
                default                    => ['type' => 'product'],
            };
        }
        return $this->camposTipo;
    }

    private function payload(string $service, string $method, array $args): array
    {
        return [
            'jsonrpc' => '2.0',
            'method'  => 'call',
            'params'  => ['service' => $service, 'method' => $method, 'args' => $args],
            'id'      => random_int(1, 999999),
        ];
    }

    private function rpc(string $service, string $method, array $args): mixed
    {
        $r = Http::timeout(120)
            ->retry(self::REINTENTOS, fn ($intento, $e) => self::espera($intento, $e), fn ($e) => self::esFreno($e))
            ->post($this->url . '/jsonrpc', $this->payload($service, $method, $args))->throw()->json();
        return $this->resultado($r);
    }

    /** Odoo frenó el pedido por exceso (429), está saturado (502-504) o no hubo conexión: conviene esperar y reintentar. */
    private static function esFreno($r): bool
    {
        if ($r instanceof RequestException) $r = $r->response;
        if ($r instanceof Response) return in_array($r->status(), [429, 502, 503, 504], true);
        return $r instanceof ConnectionException;
    }

    /** Milisegundos a esperar: lo que pide Odoo (Retry-After) o 2, 4, 8… segundos, hasta 60. */
    private static function espera(int $intento, $r): int
    {
        if ($r instanceof RequestException) $r = $r->response;
        $pide = $r instanceof Response ? $r->header('Retry-After') : null;
        $seg = is_numeric($pide) ? (int) $pide : min(60, 2 ** $intento);
        return max(1, $seg) * 1000 + random_int(0, 700);
    }

    private function resultado(?array $r): mixed
    {
        if (isset($r['error'])) {
            $msg = $r['error']['data']['message'] ?? $r['error']['message'] ?? 'Error de Odoo';
            throw new RuntimeException('Odoo: ' . $msg);
        }
        return $r['result'] ?? null;
    }

    public function uid(): int
    {
        if ($this->uid === null) {
            $uid = $this->rpc('common', 'login', [$this->db, $this->user, $this->apiKey]);
            if (!$uid) {
                throw new RuntimeException("No se pudo entrar a Odoo ({$this->url}, base \"{$this->db}\", usuario {$this->user}): revisá usuario, base de datos y API key.");
            }
            $this->uid = (int) $uid;
        }
        return $this->uid;
    }

    public function call(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        return $this->rpc('object', 'execute_kw', [
            $this->db, $this->uid(), $this->apiKey, $model, $method, $args, (object) $kwargs,
        ]);
    }

    public function searchRead(string $model, array $domain, array $fields, int $offset = 0, int $limit = 0): array
    {
        $kw = ['fields' => $fields, 'offset' => $offset, 'order' => 'id'];
        if ($limit) $kw['limit'] = $limit;
        return $this->call($model, 'search_read', [$domain], $kw);
    }

    /**
     * Todos los registros que cumplen el dominio, leídos de a páginas en paralelo (mucho más rápido que de a una).
     */
    public function searchReadTodo(string $model, array $domain, array $fields, int $lote = 2000, int $paralelo = 2, array $context = []): array
    {
        $kw = $context ? ['context' => $context] : [];
        $total = (int) $this->call($model, 'search_count', [$domain], $kw);
        if ($total === 0) return [];
        $paginas = array_map(fn ($o) => [[$domain], ['fields' => $fields, 'offset' => $o, 'limit' => $lote, 'order' => 'id'] + $kw], range(0, $total - 1, $lote));
        $filas = [];
        foreach ($this->callMany($model, 'search_read', $paginas, $paralelo) as $r) {
            if ($r instanceof \Throwable) throw $r;
            array_push($filas, ...$r);
        }
        return $filas;
    }

    /**
     * Muchas llamadas al mismo método a la vez, de a $paralelo pedidos simultáneos.
     * @param array<int|string,array{0:array,1?:array}> $llamadas clave => [args, kwargs]
     * @return array<int|string,mixed> clave => resultado, o el error (Throwable) si esa llamada falló
     */
    public function callMany(string $model, string $method, array $llamadas, int $paralelo = 3): array
    {
        $uid = $this->uid();
        $salida = [];
        $intentos = [];
        $cola = $llamadas;
        while ($cola) {
            $lote = array_slice($cola, 0, $paralelo, true);
            $cola = array_diff_key($cola, $lote);
            $resp = Http::pool(fn (Pool $pool) => array_map(
                fn ($k) => $pool->as((string) $k)->timeout(300)->post($this->url . '/jsonrpc', $this->payload('object', 'execute_kw', [
                    $this->db, $uid, $this->apiKey, $model, $method, $lote[$k][0], (object) ($lote[$k][1] ?? []),
                ])),
                array_keys($lote)
            ));
            $frenado = null;
            foreach ($lote as $k => $llamada) {
                $r = $resp[(string) $k] ?? $resp[$k] ?? null;
                // Odoo frenó por exceso: vuelve al principio de la cola y se espera antes de seguir
                if (self::esFreno($r) && ($intentos[$k] ?? 0) < self::REINTENTOS) {
                    $intentos[$k] = ($intentos[$k] ?? 0) + 1;
                    $cola = [$k => $llamada] + $cola;
                    $frenado = [$intentos[$k], $r];
                    continue;
                }
                try {
                    if ($r instanceof \Throwable) throw $r;
                    if (!$r) throw new RuntimeException('Sin respuesta de Odoo');
                    $salida[$k] = $this->resultado($r->throw()->json());
                } catch (\Throwable $e) {
                    $salida[$k] = $e;
                }
            }
            if ($frenado) {
                usleep(self::espera(...$frenado) * 1000);
                $paralelo = max(1, intdiv($paralelo, 2));   // más despacio de acá en adelante
            }
        }
        return $salida;
    }

    /**
     * Escribe valores distintos en muchos registros con pocas llamadas: usa la importación de Odoo (load),
     * de a $lote registros por llamada. Si un lote falla, se escribe ese lote de a uno para saber cuál fue.
     * @param array<int,array> $valores id => [campo => valor] (campos que terminan en _id = relación, con su id)
     * @param callable|null $avance fn(int $hechos)
     * @return array<int,string> id => error, solo los que fallaron
     */
    public function escribirVarios(string $model, array $valores, int $lote = 200, ?callable $avance = null): array
    {
        // load necesita las mismas columnas en todo el lote: se agrupan por los campos que cambian
        $porCampos = [];
        foreach ($valores as $id => $v) {
            ksort($v);
            $porCampos[implode(',', array_keys($v))][$id] = $v;
        }
        $llamadas = [];
        foreach ($porCampos as $campos => $grupo) {
            $cols = array_map(fn ($c) => str_ends_with($c, '_id') ? "$c/.id" : $c, explode(',', $campos));
            foreach (array_chunk($grupo, $lote, true) as $i => $chunk) {
                $filas = [];
                foreach ($chunk as $id => $v) {
                    $filas[] = array_merge([(string) $id], array_map(fn ($x) => match (true) {
                        $x === null || $x === false => '',
                        is_float($x) => rtrim(rtrim(sprintf('%.6F', $x), '0'), '.'),
                        default => (string) $x,
                    }, array_values($v)));
                }
                $llamadas["$campos#$i"] = [[array_merge(['.id'], $cols), $filas], array_keys($chunk)];
            }
        }

        $errores = [];
        $hechos = 0;
        foreach (array_chunk($llamadas, 2, true) as $tanda) {
            $res = $this->callMany($model, 'load', array_map(fn ($l) => [$l[0]], $tanda), 2);
            foreach ($tanda as $k => [, $ids]) {
                $r = $res[$k] ?? null;
                $ok = is_array($r) && is_array($r['ids'] ?? null) && count($r['ids']) === count($ids)
                    && !array_filter($r['messages'] ?? [], fn ($m) => ($m['type'] ?? '') === 'error');
                if (!$ok) {
                    // el lote no entró (load deshace todo el lote): de a uno con write
                    $fallas = $this->writeMany($model, array_map(fn ($id) => [[$id], $valores[$id]], array_combine($ids, $ids)), 2);
                    $errores += $fallas;
                }
                $hechos += count($ids);
                if ($avance) $avance($hechos);
            }
        }
        return $errores;
    }

    /** Crea varios registros en una sola llamada. Devuelve los ids en el mismo orden. */
    public function crear(string $model, array $valores, array $kwargs = []): array
    {
        if (!$valores) return [];
        $ids = $this->call($model, 'create', [array_values($valores)], $kwargs);
        return array_map('intval', is_array($ids) ? $ids : [$ids]);
    }

    /** Nombres de los campos que existen en el modelo (entre los pedidos). */
    public function camposExistentes(string $model, array $campos): array
    {
        $f = $this->call($model, 'fields_get', [$campos], ['attributes' => ['type']]);
        return array_values(array_filter($campos, fn ($c) => isset($f[$c])));
    }

    public function read(string $model, array $ids, array $fields): array
    {
        return $ids ? $this->call($model, 'read', [array_values($ids)], ['fields' => $fields]) : [];
    }

    public function write(string $model, array $ids, array $valores): bool
    {
        return (bool) $this->call($model, 'write', [array_values($ids), $valores]);
    }

    private ?int $ubicacion = null;

    /** Ubicación donde entra la mercadería: ODOO_STOCK_LOCATION_ID o la "Existencias" del primer depósito. */
    public function ubicacionStock(): int
    {
        if ($this->ubicacion === null) {
            $id = $this->stockLocationId;
            if (!$id) {
                $wh = $this->searchRead('stock.warehouse', [], ['lot_stock_id'], 0, 1);
                $id = is_array($wh[0]['lot_stock_id'] ?? null) ? $wh[0]['lot_stock_id'][0] : null;
            }
            if (!$id) throw new RuntimeException('No se encontró el depósito en Odoo.');
            $this->ubicacion = (int) $id;
        }
        return $this->ubicacion;
    }

    /**
     * Suma (o resta, con negativo) unidades al stock de cada producto con un ajuste de inventario.
     * @param array<int,float> $cantidades product_id => unidades
     * @return array<int,string> product_id => error, solo los que fallaron
     */
    public function sumarStock(array $cantidades): array
    {
        $loc = $this->ubicacionStock();
        $ctx = ['context' => ['inventory_mode' => true]];
        $errores = [];
        foreach ($cantidades as $pid => $cant) {
            try {
                $quant = $this->searchRead('stock.quant', [['product_id', '=', $pid], ['location_id', '=', $loc]], ['quantity'], 0, 1)[0] ?? null;
                $actual = (float) ($quant['quantity'] ?? 0);
                $id = $quant['id'] ?? $this->call('stock.quant', 'create', [['product_id' => $pid, 'location_id' => $loc, 'inventory_quantity' => $actual + $cant]], $ctx);
                $this->call('stock.quant', 'write', [[$id], ['inventory_quantity' => $actual + $cant, 'inventory_quantity_set' => true]], $ctx);
                $r = $this->call('stock.quant', 'action_apply_inventory', [[$id]], $ctx);
                // si Odoo devuelve una pantalla (conflicto, número de serie) el ajuste no se hizo
                if (is_array($r) && isset($r['res_model'])) {
                    throw new RuntimeException('Odoo pide confirmar el ajuste a mano (' . ($r['name'] ?? $r['res_model']) . ')');
                }
            } catch (\Throwable $e) {
                $errores[$pid] = 'Stock: ' . $e->getMessage();
            }
        }
        return $errores;
    }

    /**
     * Muchos write a la vez, de a $paralelo pedidos simultáneos (uno por uno tarda minutos).
     * @param array<int|string,array{0:int[],1:array}> $escrituras clave => [ids, valores]
     * @return array<int|string,string> clave => mensaje, solo las que fallaron
     */
    public function writeMany(string $model, array $escrituras, int $paralelo = 3): array
    {
        $llamadas = array_map(fn ($e) => [[array_values($e[0]), $e[1]]], $escrituras);
        $errores = [];
        foreach ($this->callMany($model, 'write', $llamadas, $paralelo) as $k => $r) {
            if ($r instanceof \Throwable) $errores[$k] = $r->getMessage();
        }
        return $errores;
    }
}
