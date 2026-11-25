@php
    $n = fn ($v) => number_format((int) $v, 0, ',', '.');
    $dur = fn ($s) => $s >= 60 ? intdiv($s, 60) . ' min ' . ($s % 60) . ' s' : $s . ' s';
    $pctBar = $tarea && ($tarea['total'] ?? 0) > 0 ? min(100, round($tarea['hechos'] / $tarea['total'] * 100)) : null;
    $segmento = fn ($activo) => $activo ? 'bg-white text-slate-900 shadow-sm ring-1 ring-slate-200' : 'text-slate-500 hover:text-slate-800';
    $destinoTxt = ['viejo' => 'el Odoo viejo', 'nuevo' => 'el Odoo nuevo', 'ambos' => 'los dos Odoo'][$destino] ?? '';
@endphp
<div class="space-y-6" @if($enCurso) wire:poll.1500ms @endif>
    <x-cargando target="actualizarListas" texto="Leyendo los dos Odoo…" />

    {{-- Encabezado --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="page-title">Odoo viejo <span class="text-slate-300">→</span> Odoo nuevo</h1>
            <p class="page-subtitle">Pasá todo del viejo al nuevo, compará las categorías y aplicá el porcentaje de venta.</p>
        </div>
        <button wire:click="actualizarListas" class="btn-secondary" @disabled($enCurso) title="Vuelve a leer categorías y productos de los dos Odoo, sin cambiar nada">
            <x-icon name="refresh" class="h-4 w-4" /> Actualizar listas
        </button>
    </div>

    @if($mensaje)
        <div class="alert-success"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ $mensaje }}</span></div>
    @endif
    @if($error)
        <div class="alert-error"><x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ $error }}</span></div>
    @endif

    {{-- Sincronizar todo --}}
    <div class="card overflow-hidden">
        <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
            <div class="flex items-start gap-3.5">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 ring-1 ring-brand-600/10">
                    <x-icon name="database" class="h-5 w-5" />
                </span>
                <div>
                    <h2 class="card-title">Sincronizar todo</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Crea en el nuevo las categorías y productos que faltan, y deja igual el costo, la venta, la categoría y el stock. El viejo no se modifica.</p>
                </div>
            </div>
            <button wire:click="sincronizarTodo" class="btn-primary btn-lg shrink-0" @disabled($enCurso)
                    wire:confirm="Se va a pasar todo del Odoo viejo al nuevo: categorías, productos, costos, precios de venta y stock. ¿Seguimos?">
                <x-icon name="zap" class="h-4 w-4" /> Sincronizar todo
            </button>
        </div>

        {{-- Tarea en curso o último resultado --}}
        @if($tarea)
            <div class="border-t border-slate-200 bg-slate-50/70 px-4 py-4 sm:px-5">
                @if($enCurso)
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <div class="flex min-w-0 items-center gap-2.5">
                            <x-icon name="loader" class="h-4 w-4 shrink-0 animate-spin text-brand-600" />
                            <span class="truncate font-medium text-slate-900">{{ $tarea['titulo'] }}</span>
                            <span class="hidden text-slate-400 sm:inline">·</span>
                            <span class="hidden truncate text-slate-600 sm:inline">{{ $tarea['paso'] }}</span>
                        </div>
                        <span class="shrink-0 tabular-nums text-slate-500">{{ $dur(time() - $tarea['inicio']) }}</span>
                    </div>
                    <div class="mt-1 truncate text-sm text-slate-600 sm:hidden">{{ $tarea['paso'] }}</div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                        @if($pctBar !== null)
                            <div class="h-full rounded-full bg-brand-600 transition-all duration-500" style="width: {{ max(2, $pctBar) }}%"></div>
                        @else
                            <div class="h-full w-1/3 animate-pulse rounded-full bg-brand-500/60"></div>
                        @endif
                    </div>
                    @if($pctBar !== null)
                        <div class="mt-1.5 text-xs tabular-nums text-slate-500">{{ $n($tarea['hechos']) }} de {{ $n($tarea['total']) }} · {{ $pctBar }}%</div>
                    @endif
                    @if($sinProceso)
                        <div class="alert-error mt-3"><x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
                            <span>La tarea no arranca. Revisá que PHP pueda ejecutarse desde la línea de comandos; el detalle queda en <b>storage/logs/tareas.log</b>.</span>
                        </div>
                    @endif

                @elseif($tarea['estado'] === 'error')
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-2.5 text-sm text-red-700">
                            <x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" />
                            <div><span class="font-medium">{{ $tarea['titulo'] }}: no terminó.</span> <span class="text-red-600">{{ $tarea['error'] }}</span></div>
                        </div>
                        <button wire:click="cerrarResultado" class="btn-ghost -my-1 px-2" title="Cerrar"><x-icon name="x" class="h-4 w-4" /></button>
                    </div>

                @elseif($tarea['estado'] === 'listo' && ($r = $tarea['resumen']))
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-2 text-sm">
                            <x-icon name="check" class="h-4 w-4 text-emerald-600" />
                            <span class="font-medium text-slate-900">{{ $tarea['titulo'] }}</span>
                            <span class="text-slate-500">· terminó en {{ $dur($r['segundos'] ?? 0) }}{{ isset($tarea['fin']) ? ' · ' . date('d/m H:i', $tarea['fin']) : '' }}</span>
                        </div>
                        <button wire:click="cerrarResultado" class="btn-ghost -my-1 px-2" title="Cerrar"><x-icon name="x" class="h-4 w-4" /></button>
                    </div>

                    @if($tarea['tipo'] === 'sincronizar')
                        @php $tiles = [
                            ['Categorías creadas', $r['categorias_creadas'], 'text-slate-900'],
                            ['Productos creados', $r['productos_creados'], 'text-slate-900'],
                            ['Actualizados', $r['productos_actualizados'], 'text-slate-900'],
                            ['Stock ajustado', $r['stock_ajustado'], 'text-slate-900'],
                            ['Ya estaban iguales', $r['sin_cambios'], 'text-slate-500'],
                            ['Errores', $r['errores'], $r['errores'] ? 'text-red-600' : 'text-slate-500'],
                        ]; @endphp
                        <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                            @foreach($tiles as [$t, $v, $color])
                                <div class="rounded-md border border-slate-200 bg-white px-3 py-2.5">
                                    <div class="text-xs text-slate-500">{{ $t }}</div>
                                    <div class="mt-0.5 text-lg font-semibold tabular-nums {{ $color }}">{{ $n($v) }}</div>
                                </div>
                            @endforeach
                        </div>
                        @if(!empty($r['detalle_errores']))
                            <details class="mt-3 text-sm">
                                <summary class="cursor-pointer font-medium text-red-700">Ver errores</summary>
                                <ul class="mt-2 max-h-60 space-y-1 overflow-y-auto rounded-md border border-red-100 bg-white p-3 text-xs text-red-700">
                                    @foreach($r['detalle_errores'] as $e)<li>{{ $e }}</li>@endforeach
                                </ul>
                            </details>
                        @endif
                    @else
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach($r['destinos'] as $d)
                                <div class="rounded-md border border-slate-200 bg-white px-3.5 py-3 text-sm">
                                    <div class="font-medium text-slate-900">{{ $d['nombre'] }}</div>
                                    <div class="mt-1 text-slate-600">
                                        <span class="font-semibold tabular-nums text-slate-900">{{ $n($d['modificados']) }}</span> precios cambiados
                                        <span class="text-slate-400">·</span> {{ $n($d['iguales']) }} ya estaban bien
                                        @if($d['sin_costo'])<span class="text-slate-400">·</span> {{ $n($d['sin_costo']) }} sin costo (no se tocan)@endif
                                    </div>
                                    @if($d['faltan'])<div class="mt-1 text-xs text-amber-700">{{ $d['faltan'] }} {{ $d['faltan'] === 1 ? 'categoría no existe' : 'categorías no existen' }} en este Odoo.</div>@endif
                                    @foreach($d['errores'] as $e)<div class="mt-1 text-xs text-red-700">{{ $e }}</div>@endforeach
                                </div>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>
        @endif
    </div>

    {{-- Resumen de los dos Odoo --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach([
            ['Odoo viejo', $stats['cats_viejo'], $stats['prods_viejo'], 'bg-slate-900'],
            ['Odoo nuevo', $stats['cats_nuevo'], $stats['prods_nuevo'], 'bg-brand-600'],
        ] as [$t, $c, $p, $dot])
            <div class="card px-4 py-3.5">
                <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500"><span class="dot {{ $dot }}"></span>{{ $t }}</div>
                <div class="mt-1.5 text-2xl font-semibold tabular-nums text-slate-900">{{ $n($c) }} <span class="text-sm font-normal text-slate-500">categorías</span></div>
                <div class="text-sm tabular-nums text-slate-500">{{ $n($p) }} productos</div>
            </div>
        @endforeach
        <button wire:click="$set('filtro', 'viejo')" class="card px-4 py-3.5 text-left transition hover:border-amber-300">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Solo en el viejo</div>
            <div class="mt-1.5 text-2xl font-semibold tabular-nums {{ $stats['solo_viejo'] ? 'text-amber-600' : 'text-slate-900' }}">{{ $n($stats['solo_viejo']) }}</div>
            <div class="text-sm text-slate-500">{{ $stats['solo_viejo'] ? 'faltan en el nuevo' : 'nada pendiente' }}</div>
        </button>
        <button wire:click="$set('filtro', 'nuevo')" class="card px-4 py-3.5 text-left transition hover:border-brand-300">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">Solo en el nuevo</div>
            <div class="mt-1.5 text-2xl font-semibold tabular-nums text-slate-900">{{ $n($stats['solo_nuevo']) }}</div>
            <div class="text-sm text-slate-500">no están en el viejo</div>
        </button>
    </div>

    {{-- Categorías y % de venta --}}
    <div class="card">
        <div class="card-header flex-wrap">
            <div>
                <h2 class="card-title">Categorías y porcentaje de venta</h2>
                <p class="mt-0.5 text-sm text-slate-500">Precio de venta = costo × (1 + %). Los productos sin costo no se tocan.</p>
            </div>
        </div>

        {{-- Barra para aplicar --}}
        <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/70 px-4 py-3 sm:px-5 lg:flex-row lg:items-center">
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-sm font-medium text-slate-700">Aplicar en</span>
                <div class="inline-flex rounded-lg bg-slate-100 p-1">
                    @foreach(['viejo' => 'Viejo', 'nuevo' => 'Nuevo', 'ambos' => 'Los dos'] as $v => $t)
                        <button type="button" wire:click="$set('destino', '{{ $v }}')" class="rounded-md px-3 py-1 text-sm font-medium transition {{ $segmento($destino === $v) }}">{{ $t }}</button>
                    @endforeach
                </div>
                <label class="flex items-center gap-2 text-sm text-slate-700">
                    Redondeo
                    <select wire:model.live="redondeo" class="input !w-auto !py-1.5">
                        <option value="0">Sin redondear</option>
                        <option value="10">A $10</option>
                        <option value="50">A $50</option>
                        <option value="100">A $100</option>
                    </select>
                </label>
            </div>
            <button wire:click="aplicar" class="btn-success lg:ml-auto" @disabled($enCurso || !$conPct)
                    wire:confirm="Se va a cambiar el precio de venta de los productos de {{ $conPct }} {{ $conPct === 1 ? 'categoría' : 'categorías' }} en {{ $destinoTxt }}. ¿Seguimos?">
                <x-icon name="check" class="h-4 w-4" /> Aplicar porcentajes
                <span class="rounded bg-white/20 px-1.5 text-xs tabular-nums">{{ $conPct }}</span>
            </button>
        </div>

        {{-- Filtros --}}
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 px-4 py-3 sm:px-5">
            <div class="relative w-full sm:w-auto sm:min-w-[240px] sm:flex-1">
                <x-icon name="search" class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                <input wire:model.live.debounce.300ms="buscar" type="text" placeholder="Buscar categoría" class="input pl-9">
            </div>
            <div class="no-scrollbar inline-flex max-w-full overflow-x-auto rounded-lg bg-slate-100 p-1">
                @foreach(['todas' => 'Todas', 'ambos' => 'En los dos', 'viejo' => 'Solo viejo', 'nuevo' => 'Solo nuevo'] as $v => $t)
                    <button type="button" wire:click="$set('filtro', '{{ $v }}')" class="whitespace-nowrap rounded-md px-3 py-1 text-sm font-medium transition {{ $segmento($filtro === $v) }}">{{ $t }}</button>
                @endforeach
            </div>
            <span class="text-sm text-slate-500 sm:ml-auto">{{ count($filas) }} {{ count($filas) === 1 ? 'categoría' : 'categorías' }}</span>
        </div>

        <div class="overflow-x-auto">
            <table class="table table-cards">
                <thead>
                    <tr>
                        <th>Categoría</th>
                        <th class="!text-right">Viejo</th>
                        <th class="!text-right">Nuevo</th>
                        <th class="!text-center">% venta</th>
                        <th class="w-px"></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($filas as $f)
                    @php
                        $partes = array_map('trim', explode('/', $f['nombre']));
                        $hoja = array_pop($partes);
                        $lleno = trim((string) ($pct[$f['id']] ?? '')) !== '';
                    @endphp
                    <tr wire:key="o-{{ $f['id'] }}" class="hover:bg-slate-50/60">
                        <td class="!align-middle">
                            <div class="font-medium text-slate-900">{{ $hoja }}</div>
                            @if($partes)<div class="text-xs text-slate-400">{{ implode(' / ', $partes) }}</div>@endif
                        </td>
                        <td class="td-half !align-middle text-right tabular-nums max-md:!text-left" data-label="Viejo">
                            @if($f['viejo'])
                                <span class="text-slate-700">{{ $n($f['viejo']['n']) }}</span>
                            @else
                                <span class="badge-gray">No está</span>
                            @endif
                        </td>
                        <td class="td-half !align-middle text-right tabular-nums max-md:!text-left" data-label="Nuevo">
                            @if($f['nuevo'])
                                <span class="text-slate-700">{{ $n($f['nuevo']['n']) }}</span>
                            @else
                                <span class="badge-yellow">Falta</span>
                            @endif
                        </td>
                        <td class="td-half !align-middle" data-label="% venta">
                            <div class="relative mx-auto w-24 max-md:mx-0 max-md:w-full">
                                <input type="text" inputmode="decimal" placeholder="—" wire:model.live.debounce.400ms="pct.{{ $f['id'] }}"
                                       class="input pr-7 text-center {{ $lleno ? '!border-brand-500 bg-brand-50/50' : '' }}">
                                <span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400">%</span>
                            </div>
                        </td>
                        <td class="td-half !align-middle max-md:flex max-md:items-end">
                            <button wire:click="aplicar('{{ $f['id'] }}')" class="btn-secondary w-full !px-3 md:w-auto" @disabled($enCurso)
                                    wire:confirm="¿Aplicar el porcentaje a «{{ $hoja }}» en {{ $destinoTxt }}?">
                                Aplicar
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="!py-14 text-center text-sm text-slate-500">
                        @if($buscar !== '' || $filtro !== 'todas')
                            No hay categorías con ese filtro.
                        @else
                            No hay categorías todavía. Tocá <b>Actualizar listas</b> para traerlas de los dos Odoo.
                        @endif
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
