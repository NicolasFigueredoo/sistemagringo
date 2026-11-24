<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    @include('partials.head')
    <title>{{ $title ?? 'Precios' }} · Tío Gringo</title>
    @livewireStyles
</head>
<body class="min-h-full">
    @php
        $viejo = \App\Support\Instancia::esViejo();
        $nav = $viejo ? [
            ['viejo.subir.factura', 'Cargar factura nuevo y viejo', 'camera', ['viejo.subir.factura', 'viejo.revisar']],
            ['viejo.historial', 'Historial', 'clock', ['viejo.historial']],
            ['subir', 'Volver', 'undo', []],
        ] : [
            ['subir', 'Cargar lista', 'file', ['subir', 'revisar']],
            ['subir.factura', 'Cargar factura', 'camera', ['subir.factura']],
            ['costos', 'Costos', 'check', ['costos']],
            ['categorias', 'Categorías', 'folder', ['categorias']],
            ['historial', 'Historial', 'clock', ['historial']],
        ];
        // con el sistema nuevo activo, la factura se carga en los dos Odoo a la vez
        if (!$viejo && !\App\Support\Instancia::nuevoPausado()) {
            $nav[1] = ['viejo.subir.factura', 'Cargar factura nuevo y viejo', 'camera', []];
        }
    @endphp
    <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur" x-data="{ menu: false }" @keydown.escape.window="menu = false">
        <div class="mx-auto flex h-14 max-w-7xl items-center gap-3 px-4 sm:px-6 xl:gap-6">
            <a href="{{ route($viejo ? 'viejo.subir.factura' : 'subir') }}" class="flex items-center gap-2.5">
                <span class="flex h-8 w-8 items-center justify-center rounded-md bg-slate-900 text-xs font-bold tracking-tight text-white">TG</span>
                <span class="hidden text-sm font-semibold text-slate-900 sm:block">Tío Gringo <span class="font-normal text-slate-400">/ Precios</span></span>
                @if($viejo)<span class="rounded-md bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-600/20">{{ mb_strtoupper(\App\Support\Instancia::nombre(true)) }}</span>@endif
            </a>

            {{-- Escritorio --}}
            <nav class="no-scrollbar hidden flex-1 items-center gap-1 overflow-x-auto xl:flex">
                @foreach($nav as [$ruta, $texto, $icono, $activas])
                    @php $activa = request()->routeIs(...$activas); @endphp
                    <a href="{{ route($ruta) }}"
                       class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium {{ $activa ? 'bg-slate-100 text-slate-900' : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900' }}">
                        <x-icon :name="$icono" class="h-4 w-4" />
                        {{ $texto }}
                    </a>
                @endforeach
            </nav>

            {{-- Celular: nombre de la sección actual --}}
            <div class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-900 xl:hidden">
                @foreach($nav as [$ruta, $texto, $icono, $activas])
                    @if($activas && request()->routeIs(...$activas)) {{ $texto }} @break @endif
                @endforeach
            </div>

            @auth
                <div class="flex items-center gap-1 sm:gap-3">
                    <div class="hidden text-right md:block">
                        <div class="text-sm font-medium leading-tight text-slate-900">{{ auth()->user()->name }}</div>
                        <div class="text-xs leading-tight text-slate-500">{{ auth()->user()->email }}</div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="hidden xl:block">
                        @csrf
                        <button type="submit" class="btn-ghost px-2" title="Cerrar sesión">
                            <x-icon name="logout" class="h-4 w-4" />
                        </button>
                    </form>
                </div>
            @endauth

            <button type="button" @click="menu = !menu" class="btn-ghost -mr-2 px-2.5 py-2.5 xl:hidden" aria-label="Menú" :aria-expanded="menu">
                <x-icon name="menu" class="h-5 w-5" x-show="!menu" />
                <x-icon name="x" class="h-5 w-5" x-show="menu" x-cloak />
            </button>
        </div>

        {{-- Menú desplegable del celular --}}
        <div x-show="menu" x-cloak x-transition.opacity.duration.100ms @click.outside="menu = false"
             class="absolute inset-x-0 top-full border-b border-slate-200 bg-white shadow-lg xl:hidden">
            <nav class="mx-auto max-w-7xl space-y-1 px-3 py-3">
                @foreach($nav as [$ruta, $texto, $icono, $activas])
                    @php $activa = request()->routeIs(...$activas); @endphp
                    <a href="{{ route($ruta) }}"
                       class="flex items-center gap-3 rounded-md px-3 py-3 text-base font-medium {{ $activa ? 'bg-slate-100 text-slate-900' : 'text-slate-600 hover:bg-slate-50' }}">
                        <x-icon :name="$icono" class="h-5 w-5 {{ $activa ? 'text-brand-600' : 'text-slate-400' }}" />
                        {{ $texto }}
                    </a>
                @endforeach
            </nav>
            @auth
                <div class="flex items-center justify-between gap-3 border-t border-slate-100 px-6 py-3">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-slate-900">{{ auth()->user()->name }}</div>
                        <div class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn-secondary"><x-icon name="logout" class="h-4 w-4" /> Salir</button>
                    </form>
                </div>
            @endauth
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-5 sm:px-6 sm:py-8">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
