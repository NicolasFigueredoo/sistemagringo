<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CrearUsuario extends Command
{
    protected $signature = 'usuario:crear {email} {password} {nombre=Usuario}';
    protected $description = 'Crea (o actualiza la clave de) un usuario para entrar al sistema';

    public function handle(): int
    {
        $u = User::updateOrCreate(
            ['email' => $this->argument('email')],
            ['name' => $this->argument('nombre'), 'password' => Hash::make($this->argument('password'))]
        );
        $this->info("Usuario listo: {$u->email}");
        return self::SUCCESS;
    }
}
