<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Carga extends Model
{
    protected $guarded = [];
    protected $casts = [
        'iva_incluido' => 'boolean',
        'sumar_iva'    => 'boolean',
        'aplicada_at'  => 'datetime',
        'deshecha_at'  => 'datetime',
    ];

    public function proveedor() { return $this->belongsTo(Proveedor::class); }
    public function items() { return $this->hasMany(CargaItem::class); }
    public function categoria() { return $this->belongsTo(Categoria::class); }

    /** Ajuste de categoría: recalcula la venta desde el costo actual, no viene de una lista de proveedor */
    public function getEsAjusteAttribute(): bool
    {
        return $this->categoria_id !== null;
    }

    public function getTituloAttribute(): string
    {
        return $this->es_ajuste
            ? 'Ajuste de categoría: ' . ($this->categoria?->nombre ?? '—')
            : ($this->proveedor?->nombre ?? '—');
    }
}
