<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    @include('partials.head')
    <title>{{ $title ?? 'Precios' }} · Tío Gringo</title>
    @livewireStyles
</head>
<body class="min-h-full">
    @php
        $nav = [
            ['subir', 'Cargar lista', 'upload', ['subir', 'revisar']],
            ['categorias', 'Categorías', 'folder', ['categorias']],
            ['historial', 'Historial', 'clock', ['historial']],
        ];
    @endphp
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
        <div class="mx-auto flex h-14 max-w-7xl items-center gap-6 px-4 sm:px-6">
            <a href="{{ route('subir') }}" class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 items-center justify-center rounded-md bg-slate-900 text-xs font-bold tracking-tight text-white">TG</span>
                <span class="hidden text-sm font-semibold text-slate-900 sm:block">Tío Gringo <span class="font-normal text-slate-400">/ Precios</span></span>
            </a>

            <nav class="flex flex-1 items-center gap-1 overflow-x-auto">
                @foreach($nav as [$ruta, $texto, $icono, $activas])
                    @php $activa = request()->routeIs(...$activas); @endphp
                    <a href="{{ route($ruta) }}"
                       class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium {{ $activa ? 'bg-slate-100 text-slate-900' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900' }}">
                        <x-icon :name="$icono" class="h-4 w-4" />
                        {{ $texto }}
                    </a>
                @endforeach
            </nav>

            @auth
                <div class="flex items-center gap-3">
                    <div class="hidden text-right md:block">
                        <div class="text-sm font-medium leading-tight text-slate-900">{{ auth()->user()->name }}</div>
                        <div class="text-xs leading-tight text-slate-500">{{ auth()->user()->email }}</div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-ghost px-2" title="Cerrar sesión">
                            <x-icon name="logout" class="h-4 w-4" />
                        </button>
                    </form>
                </div>
            @endauth
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
