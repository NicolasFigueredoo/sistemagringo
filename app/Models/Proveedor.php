<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Proveedor extends Model
{
    protected $table = 'proveedores';
    protected $guarded = [];
    protected $casts = ['iva_incluido' => 'boolean'];

    public function mapeos() { return $this->hasMany(Mapeo::class); }
    public function cargas() { return $this->hasMany(Carga::class); }
}
