<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    @include('partials.head')
    <title>Iniciar sesión · Tío Gringo</title>
</head>
<body class="h-full">
    <div class="flex min-h-full">
        <div class="relative hidden w-1/2 flex-col justify-between bg-slate-900 p-12 text-white lg:flex">
            <div class="flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-md bg-white text-sm font-bold text-slate-900">TG</span>
                <span class="text-sm font-semibold">Ferretería Tío Gringo</span>
            </div>
            <div class="max-w-md">
                <h2 class="text-3xl font-semibold leading-tight tracking-tight">Actualización de precios conectada con Odoo.</h2>
                <p class="mt-4 text-sm leading-relaxed text-slate-400">Cargá la lista del proveedor, revisá los cambios y aplicalos al catálogo en un solo paso. Cada carga se puede deshacer.</p>
            </div>
            <p class="text-xs text-slate-500">&copy; {{ date('Y') }} Ferretería Tío Gringo</p>
        </div>

        <div class="flex flex-1 items-center justify-center px-6 py-12">
            <div class="w-full max-w-sm">
                <div class="mb-8 flex items-center gap-2.5 lg:hidden">
                    <span class="flex h-9 w-9 items-center justify-center rounded-md bg-slate-900 text-sm font-bold text-white">TG</span>
                    <span class="text-sm font-semibold text-slate-900">Ferretería Tío Gringo</span>
                </div>

                <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Iniciar sesión</h1>
                <p class="mt-1 text-sm text-slate-500">Ingresá con tu correo y contraseña.</p>

                <form method="POST" action="{{ route('login.store') }}" class="mt-8 space-y-5">
                    @csrf

                    @if($errors->any())
                        <div class="alert-error">
                            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>{{ $errors->first() }}</span>
                        </div>
                    @endif

                    <div>
                        <label for="email" class="label">Correo electrónico</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="input">
                    </div>

                    <div>
                        <label for="password" class="label">Contraseña</label>
                        <input id="password" name="password" type="password" required autocomplete="current-password" class="input">
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="recordar" value="1" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                        Mantener la sesión iniciada
                    </label>

                    <button type="submit" class="btn-primary w-full py-2.5">Ingresar</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
