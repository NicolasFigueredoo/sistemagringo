<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente mínimo de Odoo por JSON-RPC (/jsonrpc), con API key.
 * Funciona en Odoo Online 19. Cuando Odoo saque JSON-RPC, se cambia solo esta clase.
 */
class OdooClient
{
    private ?int $uid = null;

    public function __construct(
        private string $url,
        private string $db,
        private string $user,
        private string $apiKey,
    ) {
        $this->url = rtrim($url, '/');
    }

    public static function desdeConfig(): self
    {
        return new self(config('odoo.url'), config('odoo.db'), config('odoo.user'), config('odoo.api_key'));
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
        $r = Http::timeout(120)->retry(2, 1000)->post($this->url . '/jsonrpc', $this->payload($service, $method, $args))->throw()->json();
        return $this->resultado($r);
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
                throw new RuntimeException('No se pudo entrar a Odoo: revisá usuario, base de datos y API key.');
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

    public function read(string $model, array $ids, array $fields): array
    {
        return $ids ? $this->call($model, 'read', [array_values($ids)], ['fields' => $fields]) : [];
    }

    public function write(string $model, array $ids, array $valores): bool
    {
        return (bool) $this->call($model, 'write', [array_values($ids), $valores]);
    }

    /**
     * Muchos write a la vez, de a $paralelo pedidos simultáneos (uno por uno tarda minutos).
     * @param array<int|string,array{0:int[],1:array}> $escrituras clave => [ids, valores]
     * @return array<int|string,string> clave => mensaje, solo las que fallaron
     */
    public function writeMany(string $model, array $escrituras, int $paralelo = 6): array
    {
        $uid = $this->uid();
        $errores = [];
        foreach (array_chunk($escrituras, $paralelo, true) as $lote) {
            $resp = Http::pool(fn (Pool $pool) => array_map(
                fn ($k) => $pool->as((string) $k)->timeout(120)->post($this->url . '/jsonrpc', $this->payload('object', 'execute_kw', [
                    $this->db, $uid, $this->apiKey, $model, 'write', [array_values($lote[$k][0]), $lote[$k][1]], (object) [],
                ])),
                array_keys($lote)
            ));
            foreach (array_keys($lote) as $k) {
                try {
                    $r = $resp[(string) $k] ?? null;
                    if ($r instanceof \Throwable) throw $r;
                    if (!$r) throw new RuntimeException('Sin respuesta de Odoo');
                    $this->resultado($r->throw()->json());
                } catch (\Throwable $e) {
                    $errores[$k] = $e->getMessage();
                }
            }
        }
        return $errores;
    }
}
