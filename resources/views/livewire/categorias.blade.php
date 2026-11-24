@php $pct = fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ','); @endphp
<div class="space-y-6">
    <x-cargando target="sincronizar" texto="Consultando Odoo…" />

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="page-title">Categorías</h1>
            <p class="page-subtitle">La ganancia y el descuento de cada categoría se aplican automáticamente en las próximas listas. Vacío usa el valor del proveedor.</p>
        </div>
        <button wire:click="sincronizar" class="btn-secondary">
            <x-icon name="refresh" /> Sincronizar con Odoo
        </button>
    </div>

    @if($mensaje)
        <div class="alert-success"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ $mensaje }}</span></div>
    @endif
    @if($error)
        <div class="alert-error"><x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ $error }}</span></div>
    @endif

    <div class="card">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 px-4 py-3 sm:gap-4 sm:px-5">
            <div class="relative w-full sm:w-auto sm:min-w-[260px] sm:flex-1">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                <input wire:model.live.debounce.400ms="buscar" type="text" placeholder="Buscar categoría" class="input pl-9">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input wire:model.live="soloConValores" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                Solo con valores cargados
            </label>
            <span class="text-sm text-slate-500 sm:ml-auto">{{ $categorias->count() }} {{ $categorias->count() === 1 ? 'categoría' : 'categorías' }}</span>
        </div>

        <div class="overflow-x-auto">
            <table class="table table-cards">
                <thead>
                    <tr>
                        <th>Categoría</th>
                        <th class="!text-right">Productos</th>
                        <th class="!text-center">Ganancia</th>
                        <th class="!text-center">Descuento</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($categorias as $c)
                    <tr wire:key="c-{{ $c->id }}">
                        <td class="!align-middle font-medium text-slate-900">{{ $c->nombre }}<div class="text-xs font-normal text-slate-500 md:hidden">{{ number_format($conteo[$c->id] ?? 0, 0, ',', '.') }} productos</div></td>
                        <td class="!align-middle text-right tabular-nums text-slate-500 max-md:!hidden">{{ number_format($conteo[$c->id] ?? 0, 0, ',', '.') }}</td>
                        <td class="td-half !align-middle" data-label="Ganancia">
                            <div class="relative mx-auto w-24 max-md:mx-0 max-md:w-full">
                                <input type="text" inputmode="decimal" placeholder="—" value="{{ $pct($c->margen) }}"
                                       wire:change="guardar({{ $c->id }}, 'margen', $event.target.value)"
                                       class="input pr-7 text-center {{ $c->margen !== null ? '!border-brand-500 bg-brand-50/50' : '' }}">
                                <span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400">%</span>
                            </div>
                        </td>
                        <td class="td-half !align-middle" data-label="Descuento">
                            <div class="relative mx-auto w-24 max-md:mx-0 max-md:w-full">
                                <input type="text" inputmode="decimal" placeholder="ej. 50+13" title="Varios descuentos seguidos: 50+13" value="{{ $c->descuento_texto ?: $pct($c->descuento) }}"
                                       wire:change="guardar({{ $c->id }}, 'descuento', $event.target.value)"
                                       class="input pr-7 text-center {{ $c->descuento !== null ? '!border-brand-500 bg-brand-50/50' : '' }}">
                                <span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400">%</span>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="!py-12 text-center text-sm text-slate-500">No hay categorías. Sincronizá con Odoo para traerlas.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
