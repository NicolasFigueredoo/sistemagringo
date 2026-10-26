<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LecturaFactura extends Model
{
    protected $table = 'lecturas_factura';
    protected $guarded = [];

    /** Siempre en la base principal, también desde las pantallas del Odoo viejo. */
    public function getConnectionName()
    {
        return config('database.principal') ?? parent::getConnectionName();
    }

    /** Lecturas hechas en el mes actual (las que cuentan para el tope). */
    public static function delMes(): int
    {
        return static::where('created_at', '>=', now()->startOfMonth())->count();
    }

    /** null = sin tope */
    public static function tope(): ?int
    {
        $n = (int) config('services.anthropic.facturas_por_mes');
        return $n > 0 ? $n : null;
    }
}
