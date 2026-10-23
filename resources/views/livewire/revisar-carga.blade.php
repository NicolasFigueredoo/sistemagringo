@php
    $m = fn($v) => $v === null ? '—' : '$ ' . number_format((float) $v, 2, ',', '.');
    $pct = fn($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
    $cant = fn($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
    $estilos = [
        'verde'    => ['badge-green',  'bg-emerald-500', 'Encontrado'],
        'amarillo' => ['badge-yellow', 'bg-amber-500',   'Confirmar'],
        'naranja'  => ['badge-orange', 'bg-orange-500',  'Elegir'],
        'gris'     => ['badge-gray',   'bg-slate-400',   'Sin coincidencia'],
    ];
    $filtros = [
        'todos'    => ['Todos', null],
        'verde'    => ['Encontrados', 'bg-emerald-500'],
        'amarillo' => ['Confirmar', 'bg-amber-500'],
        'naranja'  => ['Elegir', 'bg-orange-500'],
        'gris'     => ['Sin coincidencia', 'bg-slate-400'],
        'rojo'     => ['Cambio mayor a 30%', 'bg-red-500'],
    ];
@endphp
<div class="space-y-6">
    <x-cargando target="aplicar,aplicarMargenMasivo,sumarIva,elegir,aplicarCategoria" />

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('historial') }}" class="text-xs font-medium text-slate-500 hover:text-slate-700">Historial</a>
            <span class="text-xs text-slate-300">/</span>
            <span class="text-xs text-slate-500">Revisión</span>
            <h1 class="page-title mt-1">{{ $carga->titulo }}</h1>
            <p class="page-subtitle">
                {{ $carga->archivo }} · {{ $carga->created_at->format('d/m/Y H:i') }}
                @if($carga->descuento > 0) · Descuento {{ $pct($carga->descuento) }}% @endif
                @if($carga->iva_incluido) · Lista con IVA @endif
            </p>
        </div>
        @if($carga->estado === 'aplicada')
            <span class="badge-green px-3 py-1 text-sm"><x-icon name="check" class="h-3.5 w-3.5" /> Aplicada en Odoo</span>
        @elseif($carga->estado === 'deshecha')
            <span class="badge-gray px-3 py-1 text-sm"><x-icon name="undo" class="h-3.5 w-3.5" /> Deshecha</span>
        @else
            <span class="badge-yellow px-3 py-1 text-sm">En revisión</span>
        @endif
    </div>

    @if($mensaje)
        <div class="alert-success"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ $mensaje }}</span></div>
    @endif
    @if($error)
        <div class="alert-error"><x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ $error }}</span></div>
    @endif

    {{-- Resumen --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach([['Encontrados', $conteo['verde'], 'text-emerald-600'], ['Para confirmar', $conteo['amarillo'] + $conteo['naranja'], 'text-amber-600'], ['Sin coincidencia', $conteo['gris'], 'text-slate-500'], ['Seleccionados', $seleccionados, 'text-brand-600']] as [$t, $n, $c])
            <div class="card px-4 py-3">
                <div class="text-xs font-medium text-slate-500">{{ $t }}</div>
                <div class="mt-1 text-2xl font-semibold {{ $c }}">{{ number_format($n, 0, ',', '.') }}</div>
            </div>
        @endforeach
    </div>

    @if($editable)
    <section class="card">
        <div class="card-body flex flex-wrap items-end gap-6">
            <div>
                <span class="label">Ganancia para todos</span>
                <div class="flex gap-2">
                    <div class="relative">
                        <input wire:model="margenMasivo" type="text" inputmode="decimal" class="input w-28 pr-8">
                        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-slate-400">%</span>
                    </div>
                    <button wire:click="aplicarMargenMasivo" wire:confirm="¿Aplicar este porcentaje a todos los productos? Se pierden los precios editados a mano."
                            class="btn-secondary">Aplicar a todos</button>
                </div>
            </div>
            @unless($carga->es_ajuste)
            <label class="flex items-center gap-2 pb-2 text-sm font-medium text-slate-700">
                <input wire:model.live="sumarIva" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                Sumar IVA (21%)
            </label>
            @endunless
        </div>

        @if($categorias->count())
        <div class="border-t border-slate-200" x-data="{ abierto: false }">
            <button type="button" @click="abierto = !abierto" class="flex w-full items-center justify-between px-5 py-3 text-left hover:bg-slate-50">
                <span class="text-sm font-medium text-slate-700">Ganancia y descuento por categoría <span class="font-normal text-slate-400">· {{ $categorias->count() }} categorías</span></span>
                <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 transition-transform" x-bind:class="abierto && 'rotate-180'" />
            </button>
            <div x-show="abierto" x-cloak class="border-t border-slate-100">
                <p class="px-5 pt-3 text-xs text-slate-500">Dejá vacío lo que no quieras cambiar. Al aplicar se filtran abajo los productos de esa categoría.</p>
                <div class="divide-y divide-slate-100">
                    @foreach($categorias as $cat)
                        <div wire:key="cat-{{ $cat->id }}" class="flex flex-wrap items-center gap-4 px-5 py-3">
                            <div class="min-w-[220px] flex-1">
                                <div class="text-sm font-medium text-slate-900">{{ $cat->nombre }}</div>
                                <div class="text-xs text-slate-500">{{ $cat->cant }} productos</div>
                            </div>
                            <label class="flex items-center gap-2 text-xs text-slate-500">Ganancia %
                                <input wire:model="catMargen.{{ $cat->id }}" type="text" inputmode="decimal" placeholder="—" class="input w-20 text-center">
                            </label>
                            <label class="flex items-center gap-2 text-xs text-slate-500">Descuento %
                                <input wire:model="catDescuento.{{ $cat->id }}" type="text" inputmode="decimal" placeholder="—" class="input w-20 text-center">
                            </label>
                            <label class="flex items-center gap-2 text-xs text-slate-500">
                                <input wire:model="catGuardar.{{ $cat->id }}" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600"> Guardar
                            </label>
                            <button wire:click="aplicarCategoria({{ $cat->id }})" class="btn-secondary">Aplicar</button>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif
    </section>
    @endif

    {{-- Filtros --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex flex-wrap gap-1 rounded-lg border border-slate-200 bg-white p-1">
            @foreach($filtros as $k => [$label, $dot])
                <button wire:click="$set('filtro', '{{ $k }}')"
                        class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium {{ $filtro === $k ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50' }}">
                    @if($dot)<span class="dot {{ $dot }}"></span>@endif
                    {{ $label }}
                    <span class="text-xs {{ $filtro === $k ? 'text-slate-300' : 'text-slate-400' }}">{{ $conteo[$k] }}</span>
                </button>
            @endforeach
        </div>

        @if($categorias->count())
            <select wire:model.live="categoriaFiltro" class="input w-auto min-w-[220px]">
                <option value="">Todas las categorías</option>
                @foreach($categorias as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->nombre }} ({{ $cat->cant }})</option>
                @endforeach
            </select>
        @endif
    </div>

    @if($conteo['amarillo'] + $conteo['naranja'] > 0 && $editable)
        <div class="alert-info">
            <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>Los productos marcados como <b>Confirmar</b> o <b>Elegir</b> no se actualizan automáticamente: seleccionalos o elegí el producto correcto.</span>
        </div>
    @endif

    {{-- Tabla --}}
    <div class="card overflow-x-auto">
        <table class="table">
            <thead>
                <tr>
                    <th class="w-16">
                        @if($editable)
                            <div class="flex flex-col gap-0.5 normal-case tracking-normal">
                                <button wire:click="marcarTodos(true)" class="text-left text-xs font-medium text-brand-600 hover:underline">Todos</button>
                                <button wire:click="marcarTodos(false)" class="text-left text-xs font-medium text-slate-500 hover:underline">Ninguno</button>
                            </div>
                        @endif
                    </th>
                    <th>{{ $carga->es_ajuste ? 'Producto' : 'Lista del proveedor' }}</th>
                    <th>Producto en Odoo</th>
                    <th class="!text-right">Costo</th>
                    <th class="!text-center">Ganancia</th>
                    <th class="!text-right">Precio de venta</th>
                    @unless($carga->es_ajuste)<th class="!text-center">Ingresa</th>@endunless
                </tr>
            </thead>
            <tbody>
            @forelse($items as $it)
                @php [$badge, $dot, $txt] = $estilos[$it->color]; $var = $it->variacion; $grande = $var !== null && abs($var) > 30; @endphp
                <tr wire:key="it-{{ $it->id }}" class="{{ $it->error ? 'bg-red-50/60' : ($it->seleccionado ? '' : 'bg-slate-50/50') }}">
                    <td class="text-center">
                        @if($it->odoo_product_id)
                            <input type="checkbox" class="mt-0.5 h-4 w-4 cursor-pointer rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                                   @checked($it->seleccionado) @disabled(!$editable) wire:click="toggle({{ $it->id }})">
                        @endif
                        @if($it->aplicado)<div class="mt-1 text-[11px] font-semibold text-emerald-600">OK</div>@endif
                    </td>

                    <td class="max-w-xs {{ $it->seleccionado ? '' : 'opacity-70' }}">
                        <div class="font-medium text-slate-900">{{ $it->nombre ?: 'Sin descripción' }}</div>
                        <div class="mt-0.5 space-x-1 text-xs text-slate-500">
                            @if($it->barcode)<span class="font-mono">{{ $it->barcode }}</span><span>·</span>@endif
                            @if($it->codigo)<span>Cód. {{ $it->codigo }}</span><span>·</span>@endif
                            @if($it->unidad && !is_numeric($it->unidad))<span>{{ $it->unidad }}</span><span>·</span>@endif
                            <span>Lista {{ $m($it->precio_lista) }}</span>
                            @if((float) $it->descuento > 0)<span>· Dto. {{ $pct($it->descuento) }}%</span>@endif
                        </div>
                    </td>

                    <td class="max-w-sm">
                        <span class="{{ $badge }}"><span class="dot {{ $dot }}"></span>{{ $txt }}</span>
                        @if($it->odoo_product_id)
                            <div class="mt-1 font-medium text-slate-900">{{ $it->odoo_nombre }}</div>
                            @if($editable && !$carga->es_ajuste)
                                <div class="mt-1 flex gap-3">
                                    <button wire:click="abrirBuscador({{ $it->id }})" class="link text-xs">Cambiar</button>
                                    <button wire:click="quitar({{ $it->id }})" class="text-xs font-medium text-slate-500 hover:text-slate-700">No es este</button>
                                </div>
                            @endif
                        @else
                            @if($editable)
                                <div class="mt-2 space-y-1">
                                    @foreach($it->candidatos ?? [] as $cand)
                                        <button wire:click="elegir({{ $it->id }}, {{ $cand['id'] }})"
                                                class="flex w-full items-center gap-2 rounded-md border border-slate-200 px-2.5 py-1.5 text-left text-xs text-slate-700 hover:border-brand-500 hover:bg-brand-50">
                                            <x-icon name="arrow-right" class="h-3 w-3 shrink-0 text-slate-400" />
                                            <span class="truncate">{{ $cand['nombre'] }}</span>
                                        </button>
                                    @endforeach
                                    <button wire:click="abrirBuscador({{ $it->id }})" class="btn-secondary mt-1 px-2.5 py-1 text-xs">
                                        <x-icon name="search" class="h-3.5 w-3.5" /> Buscar producto
                                    </button>
                                </div>
                            @endif
                        @endif
                        @if($it->error)<div class="mt-1 text-xs text-red-600">{{ $it->error }}</div>@endif
                    </td>

                    <td class="whitespace-nowrap text-right">
                        @if($it->costo_anterior !== null)
                            <div class="text-xs text-slate-400">Antes {{ $m($it->costo_anterior) }}</div>
                        @endif
                        <div class="font-semibold text-slate-900">{{ $m($it->costo_nuevo) }}</div>
                        @if($var !== null)
                            <div class="mt-0.5 inline-flex items-center gap-1 text-xs font-medium {{ $grande ? 'rounded bg-red-50 px-1.5 text-red-700 ring-1 ring-red-600/20' : ($var > 0 ? 'text-red-600' : ($var < 0 ? 'text-emerald-600' : 'text-slate-400')) }}">
                                @if($var > 0)<x-icon name="trending-up" class="h-3 w-3" />@elseif($var < 0)<x-icon name="trending-down" class="h-3 w-3" />@endif
                                {{ $pct(abs($var)) }}%
                            </div>
                        @endif
                    </td>

                    <td class="text-center">
                        <div class="relative mx-auto w-24">
                            <input type="text" inputmode="decimal" value="{{ $pct($it->margen) }}" @disabled(!$editable)
                                   wire:change="cambiarMargen({{ $it->id }}, $event.target.value)"
                                   class="input pr-7 text-center">
                            <span class="pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400">%</span>
                        </div>
                    </td>

                    <td class="whitespace-nowrap text-right">
                        @if($it->precio_anterior !== null)
                            <div class="text-xs text-slate-400">Antes {{ $m($it->precio_anterior) }}</div>
                        @endif
                        <div class="relative ml-auto w-36">
                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400">$</span>
                            <input type="text" inputmode="decimal" value="{{ number_format((float) $it->precio_nuevo, 2, ',', '.') }}" @disabled(!$editable)
                                   wire:change="cambiarPrecio({{ $it->id }}, $event.target.value)"
                                   class="input pl-7 text-right font-semibold {{ $it->precio_manual ? '!border-brand-500 bg-brand-50' : '' }}">
                        </div>
                        @if($it->precio_manual)<div class="mt-0.5 text-[11px] text-brand-600">Editado a mano</div>@endif
                    </td>

                    @unless($carga->es_ajuste)
                    <td class="text-center">
                        @if($it->odoo_product_id)
                            @if($it->stock_anterior !== null)
                                <div class="text-xs text-slate-400">Hay {{ $cant($it->stock_anterior) }}</div>
                            @endif
                            <input type="text" inputmode="decimal" placeholder="—" value="{{ $it->cantidad !== null ? $cant($it->cantidad) : '' }}" @disabled(!$editable)
                                   wire:change="cambiarCantidad({{ $it->id }}, $event.target.value)"
                                   class="input mx-auto w-20 text-center {{ (float) $it->cantidad > 0 ? '!border-brand-500 bg-brand-50' : '' }}">
                            @if((float) $it->cantidad > 0 && $it->stock_anterior !== null)
                                <div class="mt-0.5 text-[11px] {{ $it->stock_aplicado ? 'font-semibold text-emerald-600' : 'text-brand-600' }}">
                                    {{ $it->stock_aplicado ? 'Sumado' : 'Queda ' . $cant((float) $it->stock_anterior + (float) $it->cantidad) }}
                                </div>
                            @endif
                        @endif
                    </td>
                    @endunless
                </tr>
            @empty
                <tr><td colspan="7" class="!py-12 text-center text-sm text-slate-500">No hay productos en este filtro.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $items->links() }}

    @if($editable)
    <div class="sticky bottom-0 z-20 -mx-4 border-t border-slate-200 bg-white/95 px-4 py-4 backdrop-blur sm:-mx-6 sm:px-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="text-sm text-slate-600">
                Se van a actualizar <span class="text-lg font-semibold text-slate-900">{{ number_format($seleccionados, 0, ',', '.') }}</span> productos en Odoo.
                @if($conStock) A <span class="font-semibold text-slate-900">{{ number_format($conStock, 0, ',', '.') }}</span> se les suma stock. @endif
            </div>
            <button wire:click="aplicar" @disabled($seleccionados === 0)
                    wire:confirm="Vas a actualizar {{ $seleccionados }} productos en Odoo. ¿Confirmás?"
                    class="btn-success btn-lg">
                <x-icon name="check" />
                Actualizar en Odoo
            </button>
        </div>
    </div>
    @endif

    {{-- Buscador manual --}}
    @if($buscandoItem)
    <div class="fixed inset-0 z-40 flex items-start justify-center bg-slate-900/30 px-4 pt-20" wire:click.self="cerrarBuscador">
        <div class="w-full max-w-xl overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <h3 class="text-base font-semibold text-slate-900">Buscar producto en Odoo</h3>
                <button wire:click="cerrarBuscador" class="btn-ghost px-1.5 py-1.5"><x-icon name="x" /></button>
            </div>
            <div class="p-4">
                <div class="relative">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                    <input wire:model.live.debounce.400ms="busqueda" type="text" autofocus placeholder="Nombre o código de barras" class="input pl-9">
                </div>
            </div>
            <div class="max-h-96 divide-y divide-slate-100 overflow-y-auto border-t border-slate-100">
                @forelse($resultados as $r)
                    <button wire:click="elegir({{ $buscandoItem }}, {{ $r['id'] }})" class="block w-full px-5 py-3 text-left hover:bg-slate-50">
                        <div class="text-sm font-medium text-slate-900">{{ $r['nombre'] }}</div>
                        <div class="mt-0.5 text-xs text-slate-500">
                            @if($r['barcode'])<span class="font-mono">{{ $r['barcode'] }}</span> · @endif Costo {{ $m($r['costo']) }} · Precio {{ $m($r['precio']) }}
                        </div>
                    </button>
                @empty
                    <p class="px-5 py-4 text-sm text-slate-500">Escribí parte del nombre, por ejemplo "palo escoba".</p>
                @endforelse
            </div>
        </div>
    </div>
    @endif
</div>
