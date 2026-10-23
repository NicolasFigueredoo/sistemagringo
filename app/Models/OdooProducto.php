<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OdooProducto extends Model
{
    protected $table = 'odoo_productos';
    public $incrementing = false;
    protected $guarded = [];
}
