<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Copia local de categorías y productos de un Odoo (tablas categorias y odoo_productos),
 * leída en paralelo. Más liviana que Procesador::sincronizarCatalogo: no toca proveedores ni códigos.
 */
class CatalogoLocal
{
    public const CAMPOS_PRODUCTO = ['id', 'name', 'barcode', 'default_code', 'standard_price', 'list_price', 'categ_id', 'product_tmpl_id'];

    /** Lee categorías y productos activos del Odoo y los guarda en la base local $conexion. Devuelve [categorías, productos]. */
    public static function actualizar(OdooClient $odoo, string $conexion): array
    {
        $cats = $odoo->searchRead('product.category', [], ['id', 'complete_name']);
        $prods = $odoo->searchReadTodo('product.product', [['active', '=', true]], self::CAMPOS_PRODUCTO);
        self::guardar($conexion, $cats, $prods);
        return [count($cats), count($prods)];
    }

    /**
     * @param array $cats filas de product.category (id, complete_name)
     * @param array $prods filas de product.product (al menos los campos de CAMPOS_PRODUCTO)
     */
    public static function guardar(string $conexion, array $cats, array $prods): void
    {
        $db = DB::connection($conexion);
        $ahora = now();
        $m2o = fn ($v) => is_array($v) ? $v[0] : null;   // many2one = [id, nombre]

        $db->transaction(function () use ($db, $cats, $prods, $ahora, $m2o) {
            // categorías: respeta los % ya cargados
            foreach (array_chunk($cats, 500) as $chunk) {
                $db->table('categorias')->upsert(array_map(fn ($c) => [
                    'id' => $c['id'], 'nombre' => mb_substr($c['complete_name'] ?: '—', 0, 250), 'created_at' => $ahora, 'updated_at' => $ahora,
                ], $chunk), ['id'], ['nombre', 'updated_at']);
            }
            if ($cats) $db->table('categorias')->whereNotIn('id', array_column($cats, 'id'))->delete();

            foreach (array_chunk($prods, 500) as $chunk) {
                $db->table('odoo_productos')->upsert(array_map(fn ($p) => [
                    'id'              => $p['id'],
                    'nombre'          => mb_substr((string) $p['name'], 0, 250),
                    'nombre_norm'     => mb_substr(Matcher::normalizar((string) $p['name']), 0, 250),
                    'barcode'         => ($p['barcode'] ?? false) ?: null,
                    'default_code'    => ($p['default_code'] ?? false) ?: null,
                    'costo'           => $p['standard_price'] ?? 0,
                    'precio'          => $p['list_price'] ?? 0,
                    'categ_id'        => $m2o($p['categ_id'] ?? null),
                    'product_tmpl_id' => $m2o($p['product_tmpl_id'] ?? null),
                    'created_at'      => $ahora,
                    'updated_at'      => $ahora,
                ], $chunk), ['id'], ['nombre', 'nombre_norm', 'barcode', 'default_code', 'costo', 'precio', 'categ_id', 'product_tmpl_id', 'updated_at']);
            }
            if ($prods) {
                // whereNotIn con miles de ids supera el límite de SQLite: se borran los que no vinieron, de a tandas
                $vinieron = array_flip(array_column($prods, 'id'));
                $sobran = array_values(array_filter($db->table('odoo_productos')->pluck('id')->all(), fn ($id) => !isset($vinieron[$id])));
                foreach (array_chunk($sobran, 500) as $ids) $db->table('odoo_productos')->whereIn('id', $ids)->delete();
            }
        });
    }

    /**
     * Clave para reconocer la misma categoría en los dos Odoo: nombre completo sin mayúsculas ni espacios de más.
     * La raíz ("All" / "Todos") cuenta como la misma aunque esté en otro idioma.
     */
    public static function claveCategoria(string $nombre): string
    {
        $partes = array_map(fn ($p) => mb_strtolower(trim($p)), explode('/', $nombre));
        if (in_array($partes[0], ['all', 'todos', 'todas', 'todo', 'all products', 'todos los productos'], true)) $partes[0] = '*';
        return implode('/', $partes);
    }
}
