<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ], [], ['email' => 'correo', 'password' => 'contraseña']);

        $clave = Str::lower($datos['email']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($clave, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Demasiados intentos. Probá de nuevo en ' . RateLimiter::availableIn($clave) . ' segundos.',
            ]);
        }

        if (!Auth::attempt($datos, $request->boolean('recordar'))) {
            RateLimiter::hit($clave, 60);
            throw ValidationException::withMessages(['email' => 'Correo o contraseña incorrectos.']);
        }

        RateLimiter::clear($clave);
        $request->session()->regenerate();
        return redirect()->intended(route('subir'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
