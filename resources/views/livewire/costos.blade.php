@php
    $m = fn($v) => $v === null ? '—' : '$ ' . number_format((float) $v, 2, ',', '.');
    $avance = $conteo['todos'] ? round($conteo['actualizados'] / $conteo['todos'] * 100) : 0;
    $filtros = [
        'faltan'       => ['Falta actualizar', 'bg-slate-400'],
        'actualizados' => ['Actualizados', 'bg-emerald-500'],
        'viejos'       => ['Viejos', 'bg-amber-500'],
        'todos'        => ['Todos', null],
    ];
@endphp
<div class="space-y-6">
    <div>
        <h1 class="page-title">Costos</h1>
        <p class="page-subtitle">Qué productos ya tienen el costo actualizado por una lista o factura, y cuáles faltan.</p>
    </div>

    <div class="card px-4 py-4 sm:px-5">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div class="text-sm text-slate-600">
                <span class="text-2xl font-semibold text-emerald-600">{{ number_format($conteo['actualizados'], 0, ',', '.') }}</span>
                de {{ number_format($conteo['todos'], 0, ',', '.') }} productos con costo actualizado
                @if($categoria !== '')<span class="text-slate-400">(en esta categoría)</span>@endif
            </div>
            <div class="text-sm font-semibold text-slate-900">{{ $avance }}%</div>
        </div>
        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
            <div class="h-full rounded-full bg-emerald-500" style="width: {{ $avance }}%"></div>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <div class="no-scrollbar flex w-full gap-1 overflow-x-auto rounded-lg border border-slate-200 bg-white p-1 sm:inline-flex sm:w-auto sm:flex-wrap">
            @foreach($filtros as $k => [$label, $dot])
                <button wire:click="$set('estado', '{{ $k }}')"
                        class="flex shrink-0 items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium {{ $estado === $k ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50' }}">
                    @if($dot)<span class="dot {{ $dot }}"></span>@endif
                    {{ $label }}
                    <span class="text-xs {{ $estado === $k ? 'text-slate-300' : 'text-slate-400' }}">{{ number_format($conteo[$k], 0, ',', '.') }}</span>
                </button>
            @endforeach
        </div>
        @if($estado === 'viejos')
            <label class="flex items-center gap-2 text-sm text-slate-600">más de
                <select wire:model.live="dias" class="input w-auto">
                    @foreach([30, 60, 90, 180] as $d)<option value="{{ $d }}">{{ $d }} días</option>@endforeach
                </select>
            </label>
        @endif
        <div class="relative w-full sm:w-auto sm:min-w-[220px] sm:flex-1">
            <x-icon name="search" class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
            <input wire:model.live.debounce.400ms="busqueda" type="text" placeholder="Buscar por nombre o código" class="input pl-9">
        </div>
        <select wire:model.live="categoria" class="input w-full sm:w-auto sm:min-w-[220px]">
            <option value="">Todas las categorías</option>
            @foreach($categorias as $cat)<option value="{{ $cat->id }}">{{ $cat->nombre }}</option>@endforeach
        </select>
    </div>

    <div class="card overflow-x-auto">
        <table class="table table-cards">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="!text-right">Costo en Odoo</th>
                    <th>Último costo</th>
                    <th class="!text-right"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($productos as $p)
                @php $o = $origen[$p->id] ?? null; $aMano = $p->costo_revisado_at && (!$p->fecha_carga || $p->costo_revisado_at > $p->fecha_carga); @endphp
                <tr wire:key="p-{{ $p->id }}">
                    <td class="max-w-md">
                        <div class="font-medium text-slate-900">{{ $p->nombre }}</div>
                        <div class="mt-0.5 space-x-1 text-xs text-slate-500">
                            @if($p->default_code)<span>Ref. <span class="font-mono">{{ $p->default_code }}</span></span>@endif
                            @if($p->barcode)<span class="font-mono">{{ $p->barcode }}</span>@endif
                        </div>
                    </td>
                    <td class="td-half whitespace-nowrap text-right font-semibold text-slate-900" data-label="Costo en Odoo">{{ $m($p->costo) }}</td>
                    <td class="td-half whitespace-nowrap" data-label="Último costo">
                        @if($p->fecha_costo)
                            @php $fecha = \Illuminate\Support\Carbon::parse($p->fecha_costo); @endphp
                            <div class="text-sm text-slate-900" title="{{ $fecha->format('d/m/Y H:i') }}">{{ $fecha->format('d/m/Y') }} <span class="text-xs text-slate-400 max-md:block">· {{ $fecha->diffForHumans() }}</span></div>
                            <div class="text-xs text-slate-500">
                                @if($aMano) Marcado a mano
                                @elseif($o) <a href="{{ route('revisar', $o->carga_id) }}" class="link">{{ $o->tipo === 'factura' ? 'Factura' : 'Lista' }} de {{ $o->proveedor }}</a>
                                @endif
                            </div>
                        @else
                            <span class="badge-gray"><span class="dot bg-slate-400"></span>Falta actualizar</span>
                        @endif
                    </td>
                    <td class="whitespace-nowrap text-right">
                        @if($aMano)
                            <button wire:click="marcar({{ $p->id }}, false)" class="text-xs font-medium text-slate-500 hover:text-slate-700">Desmarcar</button>
                        @else
                            <button wire:click="marcar({{ $p->id }}, true)" class="btn-secondary px-2.5 py-1 text-xs max-md:w-full max-md:py-2" title="El costo de Odoo está bien aunque no vino de una lista">
                                <x-icon name="check" class="h-3.5 w-3.5" /> Costo revisado
                            </button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="!py-12 text-center text-sm text-slate-500">No hay productos en este filtro.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $productos->links() }}
</div>
