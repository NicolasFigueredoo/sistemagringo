@php
    $m = fn($v) => $v === null ? '—' : '$ ' . number_format((float) $v, 2, ',', '.');
    $pct = fn($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
    $cant = fn($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
    $estilos = [
        'verde'    => ['badge-green',  'bg-emerald-500', 'Encontrado'],
        'amarillo' => ['badge-yellow', 'bg-amber-500',   'Confirmar'],
        'naranja'  => ['badge-orange', 'bg-orange-500',  'Elegir'],
        'azul'     => ['badge-blue',   'bg-brand-500',   'Se crea nuevo'],
        'gris'     => ['badge-gray',   'bg-slate-400',   'Sin coincidencia'],
    ];
    $filtros = [
        'todos'    => ['Todos', null],
        'verde'    => ['Encontrados', 'bg-emerald-500'],
        'amarillo' => ['Confirmar', 'bg-amber-500'],
        'naranja'  => ['Elegir', 'bg-orange-500'],
        'crear'    => ['Se crean', 'bg-brand-500'],
        'gris'     => ['Sin coincidencia', 'bg-slate-400'],
        'rojo'     => ['Cambio mayor a 30%', 'bg-red-500'],
    ];
    if ($dosOdoo) $filtros = array_slice($filtros, 0, 5, true) + ['falta' => ['Falta en un Odoo', 'bg-amber-500']] + $filtros;
    // "Odoo viejo" -> "viejo"
    $corto = fn($n) => trim(preg_replace('/^odoo\s*/i', '', (string) $n)) ?: $n;
@endphp
<div class="space-y-6">
    <x-cargando target="aplicar,aplicarMargenMasivo,sumarIva,iva,elegir,ignorar,recorrer,siguiente,aplicarCategoria,crearTodos,crearEnOtro,crearEnEste,guardarCrear,crearRapido,quitar" />

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ \App\Support\Instancia::ruta('historial') }}" class="text-xs font-medium text-slate-500 hover:text-slate-700">Historial</a>
            <span class="text-xs text-slate-300">/</span>
            <span class="text-xs text-slate-500">Revisión</span>
            <h1 class="page-title mt-1">{{ $carga->titulo }}</h1>
            <p class="page-subtitle">
                {{ $carga->archivo }} · {{ $carga->created_at->format('d/m/Y H:i') }}
                @if($carga->descuento > 0) · Descuento {{ $carga->descuento_texto ? $carga->descuento_texto . ' (' . $pct($carga->descuento) . '%)' : $pct($carga->descuento) . '%' }} @endif
                @if($carga->es_factura)
                    · {{ $carga->agregar_iva ? 'Costo con IVA agregado' : ($carga->iva_incluido ? 'IVA descontado del costo' : 'Costo tal cual la factura') }}
                @elseif($carga->iva_incluido) · Lista con IVA @endif
            </p>
        </div>
        @if($carga->estado === 'aplicada')
            <span class="badge-green px-3 py-1 text-sm"><x-icon name="check" class="h-3.5 w-3.5" /> Aplicada en Odoo</span>
        @elseif($carga->estado === 'aplicando')
            <span class="badge-blue px-3 py-1 text-sm">Actualizando en Odoo…</span>
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

    @if($carga->estado === 'aplicando')
        @php $total = max(1, (int) $carga->progreso_total); $hecho = (int) $carga->progreso_hecho; @endphp
        <div wire:poll.3s="progreso" class="card px-5 py-4">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div class="flex items-center gap-2 text-sm font-medium text-slate-900">
                    <x-icon name="loader" class="h-4 w-4 animate-spin text-brand-600" />
                    Actualizando en Odoo: {{ number_format($hecho, 0, ',', '.') }} de {{ number_format($carga->progreso_total, 0, ',', '.') }} productos
                    @if($carga->progreso_errores)<span class="text-xs font-normal text-red-600">· {{ $carga->progreso_errores }} con error</span>@endif
                </div>
                <div class="text-sm font-semibold text-slate-900">{{ round($hecho / $total * 100) }}%</div>
            </div>
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full bg-brand-500 transition-all" style="width: {{ round($hecho / $total * 100) }}%"></div>
            </div>
            <p class="mt-2 text-xs text-slate-500">Se hace de a tandas de 50 y cada tanda queda guardada. Si se corta, sigue desde donde quedó.</p>
        </div>
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

    @if($carga->es_factura)
    <section class="card">
        <div class="card-body flex flex-wrap items-start gap-6">
            @if($editable)
            <div>
                <span class="label">IVA de los precios de la factura</span>
                <div class="inline-flex gap-1 rounded-lg border border-slate-200 bg-white p-1">
                    @foreach(['tal_cual' => 'Tal cual', 'sacar' => 'Sacar IVA', 'agregar' => 'Agregar IVA'] as $k => $texto)
                        <button type="button" wire:click="$set('iva', '{{ $k }}')"
                                class="rounded-md px-3 py-1.5 text-sm font-medium {{ $iva === $k ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50' }}">
                            {{ $texto }}
                        </button>
                    @endforeach
                </div>
                <p class="mt-1.5 text-xs text-slate-500">
                    Solo se actualiza el costo y se suma el stock. El precio de venta no se toca.
                    @if($otroNombre)<br>Se busca en el {{ $esteNombre }} y en el {{ $otroNombre }}: el costo se actualiza y el stock se suma en los dos. Si falta en uno, se puede crear con los datos del otro.@endif
                </p>
            </div>
            @endif
            @if($carga->fotos)
            <div>
                <span class="label">Factura</span>
                <div class="flex flex-wrap gap-2">
                    @foreach($carga->fotos as $n => $ruta)
                        <a href="{{ \App\Support\Instancia::ruta('carga.foto', [$carga, $n]) }}" target="_blank" title="Ver hoja {{ $n + 1 }}"
                           class="relative block h-20 w-16 overflow-hidden rounded-md border border-slate-200 bg-slate-100 hover:border-brand-500">
                            @if(str_ends_with(strtolower($ruta), '.pdf'))
                                <span class="flex h-full items-center justify-center"><x-icon name="file" class="h-5 w-5 text-slate-400" /></span>
                            @else
                                <img src="{{ \App\Support\Instancia::ruta('carga.foto', [$carga, $n]) }}" alt="Hoja {{ $n + 1 }}" class="h-full w-full object-cover">
                            @endif
                            <span class="absolute left-1 top-1 rounded bg-slate-900/70 px-1 text-[10px] font-medium text-white">{{ $n + 1 }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
            @endif
        </div>
    </section>
    @elseif($editable && (!$carga->solo_costo || $categorias->count()))
    <section class="card">
        @unless($carga->solo_costo)
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
        @endunless

        @if($categorias->count())
        <div class="{{ $carga->solo_costo ? '' : 'border-t border-slate-200' }}" x-data="{ abierto: false }">
            <button type="button" @click="abierto = !abierto" class="flex w-full items-center justify-between px-5 py-3 text-left hover:bg-slate-50">
                <span class="text-sm font-medium text-slate-700">{{ $carga->solo_costo ? 'Descuento por categoría' : 'Ganancia y descuento por categoría' }} <span class="font-normal text-slate-400">· {{ $categorias->count() }} categorías</span></span>
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
                            @unless($carga->solo_costo)
                            <label class="flex items-center gap-2 text-xs text-slate-500">Ganancia %
                                <input wire:model="catMargen.{{ $cat->id }}" type="text" inputmode="decimal" placeholder="—" class="input w-20 text-center">
                            </label>
                            @endunless
                            <label class="flex items-center gap-2 text-xs text-slate-500">Descuento %
                                <input wire:model="catDescuento.{{ $cat->id }}" type="text" inputmode="decimal" placeholder="ej. 50+13" title="Varios descuentos seguidos: 50+13" class="input w-24 text-center">
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

    @if($sospechosos > 0)
        <div class="alert-error items-center">
            <x-icon name="alert" class="h-4 w-4 shrink-0" />
            <span class="flex-1"><b>{{ number_format($sospechosos, 0, ',', '.') }}</b> productos cambian el costo más de 50% y quedaron <b>sin marcar</b>: puede ser un precio mal leído. Comparalos con la lista y marcá los que estén bien.</span>
            <button wire:click="$set('filtro', 'rojo')" class="btn-secondary shrink-0 px-3 py-1.5 text-xs">Ver cuáles</button>
        </div>
    @endif

    @if($pendientes > 0)
        <div class="alert-info items-center">
            <x-icon name="info" class="h-4 w-4 shrink-0" />
            <span class="flex-1"><b>{{ number_format($pendientes, 0, ',', '.') }}</b> productos no se encontraron por código de barras ni referencia. Revisalos uno por uno: asocialos a un producto de Odoo, crealos nuevos o marcá "No hacer nada".</span>
            <div class="flex shrink-0 flex-wrap gap-2">
                <button wire:click="recorrer" class="btn-primary px-3 py-1.5 text-xs"><x-icon name="search" class="h-3.5 w-3.5" /> Revisar uno por uno</button>
                @unless($carga->es_ajuste)
                <button wire:click="crearTodos" wire:confirm="Se van a marcar para crear como productos nuevos{{ $otroNombre ? ' (en los dos Odoo)' : '' }} todos los que no se encontraron. Se crean recién al tocar &quot;Actualizar en Odoo&quot;. ¿Seguimos?"
                        class="btn-secondary px-3 py-1.5 text-xs"><x-icon name="plus" class="h-3.5 w-3.5" /> Crear todos como nuevos</button>
                @endunless
            </div>
        </div>
    @endif

    @if($faltanOtro > 0)
        <div class="alert-info items-center">
            <x-icon name="info" class="h-4 w-4 shrink-0" />
            <span class="flex-1"><b>{{ number_format($faltanOtro, 0, ',', '.') }}</b> productos están en el {{ $esteNombre }} pero <b>no existen en el {{ $otroNombre }}</b>: ahí no se actualiza el costo ni se suma el stock.</span>
            <div class="flex shrink-0 flex-wrap gap-2">
                <button wire:click="$set('filtro', 'falta')" class="btn-secondary px-3 py-1.5 text-xs">Ver cuáles</button>
                <button wire:click="crearEnOtro" class="btn-secondary px-3 py-1.5 text-xs"><x-icon name="plus" class="h-3.5 w-3.5" /> Crearlos en el {{ $otroNombre }}</button>
            </div>
        </div>
    @endif

    @if($faltanEste > 0)
        <div class="alert-info items-center">
            <x-icon name="info" class="h-4 w-4 shrink-0" />
            <span class="flex-1"><b>{{ number_format($faltanEste, 0, ',', '.') }}</b> productos están en el {{ $otroNombre }} pero <b>no existen en el {{ $esteNombre }}</b>. Se pueden crear acá con los datos del {{ $otroNombre }} (nombre, categoría, venta, códigos).</span>
            <div class="flex shrink-0 flex-wrap gap-2">
                <button wire:click="$set('filtro', 'falta')" class="btn-secondary px-3 py-1.5 text-xs">Ver cuáles</button>
                <button wire:click="crearEnEste" class="btn-secondary px-3 py-1.5 text-xs"><x-icon name="plus" class="h-3.5 w-3.5" /> Crearlos en el {{ $esteNombre }}</button>
            </div>
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
                    <th>{{ $carga->es_ajuste ? 'Producto' : ($carga->es_factura ? 'Factura' : 'Lista del proveedor') }}</th>
                    <th>Producto en Odoo</th>
                    <th class="!text-right">Costo</th>
                    @unless($carga->solo_costo)
                    <th class="!text-center">Ganancia</th>
                    <th class="!text-right">Precio de venta</th>
                    @endunless
                    @unless($carga->es_ajuste)<th class="!text-center">Ingresa</th>@endunless
                </tr>
            </thead>
            <tbody>
            @forelse($items as $it)
                @php [$badge, $dot, $txt] = $estilos[$it->color]; $var = $it->variacion; $grande = $var !== null && abs($var) > 30; if ($it->match === 'ignorado') $txt = 'No hacer nada'; @endphp
                <tr wire:key="it-{{ $it->id }}" class="{{ $it->error ? 'bg-red-50/60' : ($it->seleccionado ? '' : 'bg-slate-50/50') }}">
                    <td class="text-center">
                        @if($it->odoo_product_id || $it->crear)
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
                            <span>{{ $carga->es_factura ? 'Factura' : 'Lista' }} {{ $m($it->precio_lista) }}</span>
                            @if($carga->es_factura && (float) $it->cantidad > 0)<span>· x {{ $cant($it->cantidad) }}</span>@endif
                            @if((float) $it->descuento > 0)<span>· Dto. {{ $carga->descuento_texto && abs((float) $it->descuento - (float) $carga->descuento) < 0.01 ? $carga->descuento_texto : $pct($it->descuento) . '%' }}</span>@endif
                        </div>
                    </td>

                    <td class="max-w-sm">
                        <span class="{{ $badge }}"><span class="dot {{ $dot }}"></span>{{ $txt }}</span>
                        @if($it->odoo_product_id)
                            <div class="mt-1 font-medium text-slate-900">@if($dosOdoo)<span class="text-[11px] font-normal text-slate-500">{{ $esteNombre }}:</span> @endif{{ $it->odoo_nombre }}</div>
                            @if($it->guardar_codigo)
                                <div class="mt-0.5 text-[11px] text-brand-600">Se guarda «{{ $it->codigo }}» como {{ $it->guardar_codigo === 'referencia' ? 'referencia interna' : 'código del proveedor' }}</div>
                            @endif
                            @if($editable && !$carga->es_ajuste)
                                <div class="mt-1 flex gap-3">
                                    <button wire:click="abrirBuscador({{ $it->id }})" class="link text-xs">Cambiar</button>
                                    <button wire:click="quitar({{ $it->id }})" class="text-xs font-medium text-slate-500 hover:text-slate-700">No es este</button>
                                </div>
                            @endif
                            @if($carga->es_factura && $otroNombre)
                                <div class="mt-1 text-[11px] {{ $it->otro_product_id ? 'text-slate-500' : 'text-amber-700' }}">
                                    @if($it->otro_product_id)
                                        {{ $otroNombre }}: {{ $it->otro_nombre }} @if($it->otro_aplicado)<span class="font-semibold text-emerald-600">· OK</span>@endif
                                    @elseif($it->crear)
                                        No existe en el {{ $otroNombre }}: se crea ahí con estos datos
                                        @if($editable)· <button wire:click="cancelarCrear({{ $it->id }})" class="font-medium underline">No crear</button>@endif
                                    @else
                                        <b>No existe en el {{ $otroNombre }}</b>
                                        @if($editable)· <button wire:click="crearRapido({{ $it->id }})" class="font-medium text-brand-600 underline">Crearlo ahí</button>@endif
                                    @endif
                                </div>
                            @endif
                        @elseif($it->crear)
                            <div class="mt-1 font-medium text-slate-900">{{ $it->crear_nombre }}</div>
                            <div class="mt-0.5 text-[11px] text-slate-500">
                                {{ $it->categoria?->nombre ?? 'Sin categoría' }}
                                @if($dosOdoo && $it->otro_product_id) · se crea en el {{ $esteNombre }} con los datos del {{ $otroNombre }} ({{ $it->otro_nombre }})
                                @elseif($otroNombre) · se crea en los dos Odoo @endif
                                @if($it->guardar_codigo && $it->codigo) · «{{ $it->codigo }}» como {{ $it->guardar_codigo === 'referencia' ? 'referencia interna' : 'código del proveedor' }}@endif
                            </div>
                            @if($editable)
                                <div class="mt-1 flex gap-3">
                                    <button wire:click="abrirCrear({{ $it->id }})" class="link text-xs">Editar</button>
                                    <button wire:click="cancelarCrear({{ $it->id }})" class="text-xs font-medium text-slate-500 hover:text-slate-700">No crear</button>
                                </div>
                            @endif
                        @else
                            @if($dosOdoo && $it->otro_product_id)
                                <div class="mt-1.5 rounded-md bg-amber-50 px-2.5 py-1.5 text-xs text-amber-800 ring-1 ring-amber-600/20">
                                    <div><b>No existe en el {{ $esteNombre }}.</b> En el {{ $otroNombre }}: {{ $it->otro_nombre }}</div>
                                    @if($editable)
                                        <div class="mt-1 flex flex-wrap gap-3">
                                            <button wire:click="crearRapido({{ $it->id }})" class="font-medium text-brand-600 underline">Crearlo en el {{ $esteNombre }}</button>
                                            <button wire:click="abrirCrear({{ $it->id }})" class="font-medium underline">Revisar datos y crear</button>
                                        </div>
                                    @endif
                                </div>
                            @endif
                            @if($editable)
                                <div class="mt-2 space-y-1">
                                    @foreach($it->candidatos ?? [] as $cand)
                                        <button wire:click="elegir({{ $it->id }}, {{ $cand['id'] }})"
                                                class="flex w-full items-center gap-2 rounded-md border border-slate-200 px-2.5 py-1.5 text-left text-xs text-slate-700 hover:border-brand-500 hover:bg-brand-50">
                                            <x-icon name="arrow-right" class="h-3 w-3 shrink-0 text-slate-400" />
                                            <span class="truncate">{{ $cand['nombre'] }}</span>
                                        </button>
                                    @endforeach
                                    <div class="mt-1 flex items-center gap-3">
                                        <button wire:click="abrirBuscador({{ $it->id }})" class="btn-secondary px-2.5 py-1 text-xs">
                                            <x-icon name="search" class="h-3.5 w-3.5" /> Buscar producto
                                        </button>
                                        @unless($carga->es_ajuste)
                                        <button wire:click="crearRapido({{ $it->id }})" class="btn-secondary px-2.5 py-1 text-xs" title="Se crea al tocar &quot;Actualizar en Odoo&quot;{{ $otroNombre ? ', en los dos Odoo' : '' }}">
                                            <x-icon name="plus" class="h-3.5 w-3.5" /> Crear nuevo
                                        </button>
                                        @endunless
                                        @if($it->match !== 'ignorado')
                                            <button wire:click="ignorar({{ $it->id }})" class="text-xs font-medium text-slate-500 hover:text-slate-700">No hacer nada</button>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        @endif
                        @if($it->error)<div class="mt-1 text-xs text-red-600">{{ $it->error }}</div>@endif
                    </td>

                    <td class="whitespace-nowrap text-right">
                        @if($it->costo_anterior !== null)
                            <div class="text-xs text-slate-400">Antes {{ $m($it->costo_anterior) }}</div>
                        @endif
                        @if($editable && !$carga->es_ajuste)
                            <div class="relative ml-auto w-32">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400">$</span>
                                <input type="text" inputmode="decimal" value="{{ number_format((float) $it->costo_nuevo, 2, ',', '.') }}"
                                       wire:change="cambiarCosto({{ $it->id }}, $event.target.value)"
                                       class="input pl-7 text-right font-semibold {{ $it->costo_manual ? '!border-brand-500 bg-brand-50' : '' }}">
                            </div>
                            @if($it->costo_manual)<div class="mt-0.5 text-[11px] text-brand-600">Costo a mano</div>@endif
                        @else
                            <div class="font-semibold text-slate-900">{{ $m($it->costo_nuevo) }}</div>
                        @endif
                        @if($var !== null)
                            <div class="mt-0.5 inline-flex items-center gap-1 text-xs font-medium {{ $grande ? 'rounded bg-red-50 px-1.5 text-red-700 ring-1 ring-red-600/20' : ($var > 0 ? 'text-red-600' : ($var < 0 ? 'text-emerald-600' : 'text-slate-400')) }}">
                                @if($var > 0)<x-icon name="trending-up" class="h-3 w-3" />@elseif($var < 0)<x-icon name="trending-down" class="h-3 w-3" />@endif
                                {{ $pct(abs($var)) }}%
                            </div>
                        @endif
                    </td>

                    @unless($carga->solo_costo)
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
                    @endunless

                    @unless($carga->es_ajuste)
                    <td class="text-center">
                        @php
                            // stock en cada Odoo: [nombre, existe (o se crea), había, ya sumado]
                            $stocks = [[$esteNombre, $it->odoo_product_id || $it->crear, $it->stock_anterior, $it->stock_aplicado]];
                            if ($dosOdoo) $stocks[] = [$otroNombre, $it->otro_product_id || $it->crear, $it->otro_stock_anterior, $it->otro_stock_aplicado];
                            $ingresa = (float) $it->cantidad > 0;
                        @endphp
                        @if($it->odoo_product_id || $it->crear || ($dosOdoo && $it->otro_product_id))
                            <input type="text" inputmode="decimal" placeholder="—" value="{{ $it->cantidad !== null ? $cant($it->cantidad) : '' }}" @disabled(!$editable)
                                   wire:change="cambiarCantidad({{ $it->id }}, $event.target.value)" title="Unidades que ingresan"
                                   class="input mx-auto w-20 text-center {{ $ingresa ? '!border-brand-500 bg-brand-50' : '' }}">
                            <div class="mt-1 space-y-0.5 whitespace-nowrap text-[11px]">
                                @foreach($stocks as [$nom, $existe, $habia, $sumado])
                                    <div class="{{ $existe ? 'text-slate-500' : 'text-amber-700' }}">
                                        @if(count($stocks) > 1)<span class="font-medium">{{ ucfirst($corto($nom)) }}:</span>@endif
                                        @if(!$existe)
                                            no existe
                                        @elseif($sumado)
                                            <span class="font-semibold text-emerald-600">sumado</span>@if($habia !== null) ({{ $cant($habia) }} → {{ $cant((float) $habia + (float) $it->cantidad) }})@endif
                                        @else
                                            hay {{ $habia !== null ? $cant($habia) : ($it->crear && !($nom === $esteNombre ? $it->odoo_product_id : $it->otro_product_id) ? '0 (nuevo)' : '—') }}
                                            @if($ingresa)<span class="text-brand-600">→ queda {{ $cant((float) $habia + (float) $it->cantidad) }}</span>@endif
                                        @endif
                                    </div>
                                @endforeach
                            </div>
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
                {{ $carga->solo_costo ? 'Se va a actualizar el costo de' : 'Se van a actualizar' }} <span class="text-lg font-semibold text-slate-900">{{ number_format($seleccionados, 0, ',', '.') }}</span> productos en Odoo.
                @if($dosOdoo && ($conStock || $conStockOtro))
                    Se suma stock a <span class="font-semibold text-slate-900">{{ number_format($conStock, 0, ',', '.') }}</span> en el {{ $esteNombre }}
                    y a <span class="font-semibold text-slate-900">{{ number_format($conStockOtro, 0, ',', '.') }}</span> en el {{ $otroNombre }}.
                @elseif($conStock) A <span class="font-semibold text-slate-900">{{ number_format($conStock, 0, ',', '.') }}</span> se les suma stock. @endif
                @if($aCrear) Se crean <span class="font-semibold text-slate-900">{{ number_format($aCrear, 0, ',', '.') }}</span> productos nuevos{{ $otroNombre ? ' (en los dos Odoo)' : '' }}. @endif
            </div>
            <button wire:click="aplicar" @disabled($seleccionados + $aCrear === 0)
                    wire:confirm="Vas a actualizar {{ $seleccionados }} productos{{ $aCrear ? ' y crear ' . $aCrear . ' nuevos' : '' }} en Odoo. ¿Confirmás?"
                    class="btn-success btn-lg">
                <x-icon name="check" />
                Actualizar en Odoo
            </button>
        </div>
    </div>
    @endif

    {{-- Buscador manual --}}
    @if($buscando)
    <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/30 px-4 py-10" wire:click.self="cerrarBuscador">
        <div class="w-full max-w-2xl overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                <div class="min-w-0">
                    <div class="text-xs font-medium text-slate-500">{{ $carga->es_factura ? 'Factura' : 'Lista del proveedor' }} · fila {{ $buscando->fila }}@if($recorriendo) · quedan {{ $pendientes }} sin encontrar @endif</div>
                    <h3 class="mt-0.5 text-base font-semibold text-slate-900">{{ $buscando->nombre ?: 'Sin descripción' }}</h3>
                    <div class="mt-1 flex flex-wrap gap-x-3 text-xs text-slate-500">
                        @if($buscando->codigo)<span>Cód. <b class="font-mono text-slate-700">{{ $buscando->codigo }}</b></span>@endif
                        @if($buscando->barcode)<span class="font-mono">{{ $buscando->barcode }}</span>@endif
                        <span>{{ $carga->es_factura ? 'Factura' : 'Lista' }} {{ $m($buscando->precio_lista) }}</span>
                        <span>Costo nuevo <b class="text-slate-700">{{ $m($buscando->costo_nuevo) }}</b></span>
                    </div>
                </div>
                <button wire:click="cerrarBuscador" class="btn-ghost shrink-0 px-1.5 py-1.5"><x-icon name="x" /></button>
            </div>

            <div class="space-y-3 p-4">
                <div class="relative">
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                    <input wire:model.live.debounce.400ms="busqueda" wire:key="busqueda-{{ $buscando->id }}" type="text" autofocus
                           placeholder="Nombre, código de barras o referencia" class="input pl-9">
                </div>

                @if($buscando->codigo)
                    <div class="rounded-md bg-slate-50 px-3 py-2.5 text-xs text-slate-600">
                        <div class="mb-1.5 font-medium text-slate-700">Al asociar, guardar el código «{{ $buscando->codigo }}» en Odoo:</div>
                        <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                            <label class="flex items-center gap-1.5 {{ $conPartner ? '' : 'opacity-50' }}" title="{{ $conPartner ? '' : 'Este proveedor no está vinculado a un contacto de Odoo' }}">
                                <input type="radio" wire:model="guardarCodigo" value="proveedor" @disabled(!$conPartner) class="h-3.5 w-3.5 text-brand-600"> Código del proveedor (pestaña Compra)
                            </label>
                            <label class="flex items-center gap-1.5">
                                <input type="radio" wire:model="guardarCodigo" value="referencia" class="h-3.5 w-3.5 text-brand-600"> Referencia interna
                            </label>
                            <label class="flex items-center gap-1.5">
                                <input type="radio" wire:model="guardarCodigo" value="" class="h-3.5 w-3.5 text-brand-600"> No guardar
                            </label>
                        </div>
                        <div class="mt-1.5 text-slate-500">Se escribe al tocar "Actualizar en Odoo". Así la próxima lista lo encuentra sola.</div>
                    </div>
                @endif
            </div>

            <div class="max-h-[50vh] divide-y divide-slate-100 overflow-y-auto border-t border-slate-100">
                @forelse($resultados as $r)
                    @php $dif = (float) $r['costo'] > 0 ? round(((float) $buscando->costo_nuevo / (float) $r['costo'] - 1) * 100, 1) : null; @endphp
                    <button wire:click="elegir({{ $buscando->id }}, {{ $r['id'] }})" wire:key="res-{{ $r['id'] }}" class="flex w-full items-center gap-4 px-5 py-3 text-left hover:bg-slate-50">
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-medium text-slate-900">{{ $r['nombre'] }}</div>
                            <div class="mt-0.5 space-x-1 text-xs text-slate-500">
                                @if($r['default_code'])<span>Ref. <span class="font-mono">{{ $r['default_code'] }}</span></span><span>·</span>@endif
                                @if($r['barcode'])<span class="font-mono">{{ $r['barcode'] }}</span><span>·</span>@endif
                                <span>Venta {{ $m($r['precio']) }}</span>
                            </div>
                        </div>
                        <div class="shrink-0 text-right text-xs">
                            <div class="text-slate-500">Costo actual <b class="text-slate-800">{{ $m($r['costo']) }}</b></div>
                            @if($dif !== null)
                                <div class="mt-0.5 font-medium {{ abs($dif) > 30 ? 'rounded bg-red-50 px-1.5 text-red-700 ring-1 ring-red-600/20' : ($dif > 0 ? 'text-red-600' : 'text-emerald-600') }}">
                                    {{ $dif > 0 ? '+' : '' }}{{ $pct($dif) }}% con la lista
                                </div>
                            @endif
                        </div>
                    </button>
                @empty
                    <p class="px-5 py-4 text-sm text-slate-500">Sin resultados. Probá con menos palabras ("palo escoba") o con el código.</p>
                @endforelse
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-5 py-3">
                <div class="flex flex-wrap gap-2">
                    <button wire:click="ignorar({{ $buscando->id }})" class="btn-secondary text-sm" title="No se actualiza y en las próximas listas de este proveedor viene ignorado">
                        No hacer nada
                    </button>
                    @unless($carga->es_ajuste)
                    <button wire:click="abrirCrear({{ $buscando->id }})" class="btn-primary text-sm">
                        <x-icon name="plus" class="h-3.5 w-3.5" /> No está: crear producto nuevo
                    </button>
                    @endunless
                </div>
                @if($recorriendo)
                    <button wire:click="siguiente" class="btn-ghost text-sm">Dejar para después <x-icon name="arrow-right" class="h-3.5 w-3.5" /></button>
                @endif
            </div>
        </div>
    </div>
    @endif
    {{-- Crear producto nuevo --}}
    @if($creando)
    <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/30 px-4 py-10" wire:click.self="cerrarCrear">
        <div class="w-full max-w-xl overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                <div class="min-w-0">
                    <div class="text-xs font-medium text-slate-500">Producto nuevo · {{ $carga->es_factura ? 'Factura' : 'Lista' }} fila {{ $creando->fila }}@if($recorriendo) · quedan {{ $pendientes }} sin encontrar @endif</div>
                    <h3 class="mt-0.5 text-base font-semibold text-slate-900">{{ $creando->nombre ?: 'Sin descripción' }}</h3>
                    <div class="mt-1 flex flex-wrap gap-x-3 text-xs text-slate-500">
                        @if($creando->codigo)<span>Cód. <b class="font-mono text-slate-700">{{ $creando->codigo }}</b></span>@endif
                        @if($creando->barcode)<span class="font-mono">{{ $creando->barcode }}</span>@endif
                        <span>Costo <b class="text-slate-700">{{ $m($creando->costo_nuevo) }}</b></span>
                        @if((float) $creando->cantidad > 0)<span>Ingresan {{ $cant($creando->cantidad) }}</span>@endif
                    </div>
                </div>
                <button wire:click="cerrarCrear" class="btn-ghost shrink-0 px-1.5 py-1.5"><x-icon name="x" /></button>
            </div>

            <form wire:submit="guardarCrear" class="space-y-4 p-5">
                <div>
                    <label class="label">Nombre en Odoo</label>
                    <input wire:model="crearNombre" type="text" class="input" autofocus>
                </div>
                <div class="grid gap-4 sm:grid-cols-[1fr_10rem]">
                    <div>
                        <label class="label">Categoría</label>
                        <select wire:model.live="crearCategoria" class="input">
                            <option value="">Sin categoría (la de Odoo por defecto)</option>
                            @foreach($todasCategorias as $c)
                                <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">Precio de venta</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-slate-400">$</span>
                            <input wire:model="crearVenta" type="text" inputmode="decimal" class="input pl-7 text-right">
                        </div>
                    </div>
                </div>
                @if($creando->codigo)
                    <div class="rounded-md bg-slate-50 px-3 py-2.5 text-xs text-slate-600">
                        <div class="mb-1.5 font-medium text-slate-700">Guardar el código «{{ $creando->codigo }}» como:</div>
                        <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                            <label class="flex items-center gap-1.5 {{ $conPartner ? '' : 'opacity-50' }}" title="{{ $conPartner ? '' : 'Este proveedor no está vinculado a un contacto de Odoo' }}">
                                <input type="radio" wire:model="guardarCodigo" value="proveedor" @disabled(!$conPartner) class="h-3.5 w-3.5 text-brand-600"> Código del proveedor
                            </label>
                            <label class="flex items-center gap-1.5">
                                <input type="radio" wire:model="guardarCodigo" value="referencia" class="h-3.5 w-3.5 text-brand-600"> Referencia interna
                            </label>
                            <label class="flex items-center gap-1.5">
                                <input type="radio" wire:model="guardarCodigo" value="" class="h-3.5 w-3.5 text-brand-600"> No guardar
                            </label>
                        </div>
                    </div>
                @endif
                <p class="text-xs text-slate-500">
                    @if($dosOdoo && $creando->otro_product_id && !$creando->odoo_product_id)
                        Se crea en el {{ $esteNombre }} al tocar "Actualizar en Odoo", con los datos del {{ $otroNombre }}, donde ya existe como «{{ $creando->otro_nombre }}» (también copia su código de barras y referencia).
                    @else
                        Se crea al tocar "Actualizar en Odoo"{{ $otroNombre ? ', en el ' . $esteNombre . ' y en el ' . $otroNombre . ' (si ahí ya existe con el mismo código o nombre, solo se vincula)' : '' }}.
                    @endif
                    @if($creando->barcode) Lleva el código de barras {{ $creando->barcode }}. @endif
                </p>
                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                    <button type="button" wire:click="cerrarCrear" class="btn-ghost text-sm">Cancelar</button>
                    <button type="submit" class="btn-primary text-sm"><x-icon name="plus" class="h-3.5 w-3.5" /> {{ $recorriendo ? 'Crear y seguir' : 'Crear' }}</button>
                </div>
            </form>
        </div>
    </div>
    @endif
</div>
