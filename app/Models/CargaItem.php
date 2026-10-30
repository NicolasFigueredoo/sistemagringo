<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CargaItem extends Model
{
    protected $guarded = [];
    protected $casts = [
        'candidatos'    => 'array',
        'seleccionado'  => 'boolean',
        'aplicado'      => 'boolean',
        'precio_manual' => 'boolean',
        'costo_manual'  => 'boolean',
    ];

    public function carga() { return $this->belongsTo(Carga::class); }
    public function categoria() { return $this->belongsTo(Categoria::class); }

    /** verde | amarillo | naranja | gris */
    public function getColorAttribute(): string
    {
        return match ($this->match) {
            'barcode', 'mapeo', 'codigo', 'manual', 'categoria' => 'verde',
            'nombre'   => 'amarillo',
            'parecido' => 'naranja',
            default    => 'gris',
        };
    }

    /** Variación del costo vs el actual en Odoo, en % */
    public function getVariacionAttribute(): ?float
    {
        if (!$this->costo_anterior || $this->costo_anterior <= 0) return null;
        return round(($this->costo_nuevo / $this->costo_anterior - 1) * 100, 1);
    }
}
