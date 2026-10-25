<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Carga extends Model
{
    protected $guarded = [];
    protected $casts = [
        'iva_incluido' => 'boolean',
        'sumar_iva'    => 'boolean',
        'agregar_iva'  => 'boolean',
        'fotos'        => 'array',
        'aplicada_at'  => 'datetime',
        'deshecha_at'  => 'datetime',
    ];

    public function proveedor() { return $this->belongsTo(Proveedor::class); }
    public function items() { return $this->hasMany(CargaItem::class); }
    public function categoria() { return $this->belongsTo(Categoria::class); }

    /** Ajuste (de una categoría o general): recalcula la venta desde el costo actual, no viene de una lista de proveedor */
    public function getEsAjusteAttribute(): bool
    {
        return $this->proveedor_id === null;
    }

    /** Factura leída por foto: solo actualiza el costo y suma el stock, la venta no se toca */
    public function getEsFacturaAttribute(): bool
    {
        return $this->tipo === 'factura';
    }

    public function getTituloAttribute(): string
    {
        return match (true) {
            $this->categoria_id !== null => 'Ajuste de categoría: ' . ($this->categoria?->nombre ?? '—'),
            $this->es_ajuste             => 'Reajuste general',
            default                      => $this->proveedor?->nombre ?? '—',
        };
    }
}
