@php
    $letra = fn($i) => chr(65 + $i);
    $seleccionado = $proveedorId && $proveedorId !== 'nuevo' ? $proveedores->firstWhere('id', $proveedorId) : null;
@endphp
<div class="space-y-6" wire:init="cargarProveedores">
    <x-cargando target="archivo,procesar,sincronizar" />

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="page-title">Cargar lista de precios</h1>
            <p class="page-subtitle">Subí la lista del proveedor en Excel o PDF. Antes de tocar Odoo vas a poder revisar cada precio.</p>
        </div>
        <div class="flex items-center gap-3">
            <div class="text-right text-xs text-slate-500">
                <div><span class="font-semibold text-slate-700">{{ number_format($catalogo, 0, ',', '.') }}</span> productos de Odoo</div>
                @if($catalogoFecha)<div>Actualizado {{ \Illuminate\Support\Carbon::parse($catalogoFecha)->diffForHumans() }}</div>@endif
            </div>
            <button wire:click="sincronizar" class="btn-secondary">
                <x-icon name="refresh" />
                Actualizar catálogo
            </button>
        </div>
    </div>

    @if($aviso)
        <div class="alert-success"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ $aviso }}</span></div>
    @endif
    @if($error)
        <div class="alert-error"><x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ $error }}</span></div>
    @endif

    {{-- Paso 1 --}}
    <section class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span class="step">1</span>
                <h2 class="card-title">Proveedor y archivo</h2>
            </div>
        </div>
        <div class="card-body grid gap-6 md:grid-cols-2">
            <div>
                <div class="mb-1.5 flex items-center justify-between">
                    <span class="label mb-0">Proveedor</span>
                    <span wire:loading.flex wire:target="cargarProveedores" class="items-center gap-1 text-xs text-slate-400">
                        <x-icon name="loader" class="h-3 w-3 animate-spin" /> Sincronizando con Odoo
                    </span>
                </div>

                <div class="relative" x-data="{ open: false, q: '' }" @click.outside="open = false" @keydown.escape="open = false">
                    <button type="button" @click="open = !open; $nextTick(() => $refs.q?.focus())"
                            class="input flex items-center justify-between text-left">
                        <span class="{{ $seleccionado || $proveedorId === 'nuevo' ? 'text-slate-900' : 'text-slate-400' }} truncate">
                            @if($seleccionado) {{ $seleccionado->nombre }}
                            @elseif($proveedorId === 'nuevo') Proveedor nuevo
                            @else Seleccioná un proveedor
                            @endif
                        </span>
                        <x-icon name="chevron-down" class="h-4 w-4 text-slate-400" />
                    </button>

                    <div x-show="open" x-cloak x-transition.opacity.duration.100ms
                         class="absolute z-20 mt-1 w-full overflow-hidden rounded-md border border-slate-200 bg-white shadow-lg">
                        <div class="border-b border-slate-100 p-2">
                            <div class="relative">
                                <x-icon name="search" class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
                                <input x-ref="q" x-model="q" type="text" placeholder="Buscar proveedor…" class="input pl-8">
                            </div>
                        </div>
                        <div class="max-h-64 overflow-y-auto py-1">
                            @forelse($proveedores as $p)
                                <button type="button" wire:key="prov-{{ $p->id }}"
                                        data-n="{{ \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($p->nombre)) }}"
                                        x-show="$el.dataset.n.includes(q.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''))"
                                        wire:click="$set('proveedorId', '{{ $p->id }}')" @click="open = false; q = ''"
                                        class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-slate-50 {{ (string) $proveedorId === (string) $p->id ? 'font-medium text-brand-700' : 'text-slate-700' }}">
                                    <span class="truncate">{{ $p->nombre }}</span>
                                    @if((string) $proveedorId === (string) $p->id)<x-icon name="check" class="h-4 w-4" />@endif
                                </button>
                            @empty
                                <p class="px-3 py-2 text-sm text-slate-500">No hay proveedores todavía.</p>
                            @endforelse
                        </div>
                        <button type="button" wire:click="$set('proveedorId', 'nuevo')" @click="open = false; q = ''"
                                class="flex w-full items-center gap-2 border-t border-slate-100 px-3 py-2.5 text-left text-sm font-medium text-brand-600 hover:bg-slate-50">
                            <x-icon name="plus" class="h-4 w-4" /> Agregar proveedor nuevo
                        </button>
                    </div>
                </div>

                @if($proveedorId === 'nuevo')
                    <input wire:model="nuevoProveedor" type="text" placeholder="Nombre del proveedor" class="input mt-2">
                @endif
            </div>

            <div>
                <span class="label">Archivo</span>
                <label class="flex cursor-pointer items-center gap-3 rounded-md border border-dashed border-slate-300 bg-slate-50 px-4 py-3 hover:border-slate-400 hover:bg-white">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-white ring-1 ring-slate-200">
                        <x-icon name="file" class="h-4 w-4 text-slate-500" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-slate-700">
                            {{ $archivo && method_exists($archivo, 'getClientOriginalName') ? $archivo->getClientOriginalName() : 'Elegir archivo' }}
                        </span>
                        <span class="block text-xs text-slate-500">Excel (.xlsx, .xls), CSV o PDF · hasta 20 MB</span>
                    </span>
                    <input wire:model="archivo" type="file" accept=".xlsx,.xls,.ods,.csv,.pdf" class="sr-only">
                </label>
                @error('archivo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
    </section>

    @if($preview)
    @php
        $campos = ['col_barcode' => 'Código de barras', 'col_codigo' => 'Código proveedor', 'col_nombre' => 'Descripción', 'col_precio' => 'Precio', 'col_cantidad' => 'Cantidad (ingreso)'];
        $mapaCols = [];
        foreach ($campos as $k => $lbl) {
            if (($columnas[$k] ?? '') !== '' && $columnas[$k] !== null) $mapaCols[(int) $columnas[$k]] = $k;
        }
    @endphp

    {{-- Paso 2 --}}
    <section class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span class="step">2</span>
                <div>
                    <h2 class="card-title">Columnas</h2>
                    <p class="text-xs text-slate-500">Detectadas automáticamente. Se guardan para la próxima lista de este proveedor.</p>
                </div>
            </div>
        </div>
        <div class="card-body space-y-5">
            <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
                @foreach($campos as $campo => $label)
                    <label class="block">
                        <span class="label">{{ $label }}</span>
                        <select wire:model.live="columnas.{{ $campo }}" class="input {{ $campo === 'col_precio' ? 'border-brand-500' : '' }}">
                            <option value="">No tiene</option>
                            @for($c = 0; $c < $ncols; $c++)
                                <option value="{{ $c }}">Columna {{ $letra($c) }}</option>
                            @endfor
                        </select>
                    </label>
                @endforeach
                <label class="block">
                    <span class="label">Primera fila de datos</span>
                    <input wire:model.live="columnas.fila_inicio" type="number" min="1" class="input">
                </label>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span class="text-slate-600">Se leyeron <span class="font-semibold text-slate-900">{{ number_format($totalFilas, 0, ',', '.') }}</span> filas del archivo. Se procesan todas.</span>
                @if($totalFilas > count($preview))
                    <span class="text-xs text-slate-500">Vista previa: primeras {{ count($preview) }}</span>
                @endif
            </div>

            <div class="overflow-x-auto rounded-md border border-slate-200">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="w-12">#</th>
                            @for($c = 0; $c < $ncols; $c++)
                                @php $es = $mapaCols[$c] ?? null; @endphp
                                <th class="whitespace-nowrap {{ $es ? '!bg-brand-50 !text-brand-700' : '' }}">
                                    {{ $letra($c) }}@if($es) · {{ $campos[$es] }}@endif
                                </th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($preview as $i => $fila)
                            <tr class="{{ ($i + 1) < (int) $columnas['fila_inicio'] ? 'text-slate-300 line-through' : '' }}">
                                <td class="!py-2 text-xs text-slate-400">{{ $i + 1 }}</td>
                                @for($c = 0; $c < $ncols; $c++)
                                    <td class="whitespace-nowrap !py-2 {{ isset($mapaCols[$c]) ? 'bg-brand-50/40' : '' }}">{{ \Illuminate\Support\Str::limit($fila[$c] ?? '', 45) }}</td>
                                @endfor
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- Paso 3 --}}
    <section class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span class="step">3</span>
                <h2 class="card-title">Condiciones</h2>
            </div>
        </div>
        <div class="card-body space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <label class="block">
                    <span class="label">Descuento del proveedor</span>
                    <div class="relative">
                        <input wire:model="descuento" type="text" inputmode="decimal" class="input pr-8">
                        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-400">%</span>
                    </div>
                </label>
                <label class="block">
                    <span class="label">Ganancia</span>
                    <div class="relative">
                        <input wire:model="margen" type="text" inputmode="decimal" class="input pr-8">
                        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-400">%</span>
                    </div>
                </label>
            </div>

            <div class="space-y-3">
                <label class="flex items-start gap-3 text-sm">
                    <input wire:model="ivaIncluido" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span><span class="font-medium text-slate-900">La lista ya incluye IVA</span><span class="block text-slate-500">Se descuenta el IVA para calcular el costo.</span></span>
                </label>
                <label class="flex items-start gap-3 text-sm">
                    <input wire:model="sumarIva" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span><span class="font-medium text-slate-900">Sumar IVA (21%) al precio de venta</span></span>
                </label>
            </div>
        </div>
        <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-5 py-4 rounded-b-lg">
            <button wire:click="procesar" class="btn-primary btn-lg">
                Revisar precios
                <x-icon name="arrow-right" />
            </button>
        </div>
    </section>
    @endif
</div>
