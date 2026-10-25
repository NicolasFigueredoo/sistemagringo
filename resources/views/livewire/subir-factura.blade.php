@php
    $opcionesIva = [
        'auto'     => ['Detectar', 'La IA se fija si la factura trae los precios con IVA y lo saca.'],
        'tal_cual' => ['Tal cual', 'El costo es el precio de la factura.'],
        'sacar'    => ['Sacar IVA', 'Los precios vienen con IVA: el costo se guarda sin IVA.'],
        'agregar'  => ['Agregar IVA', 'Los precios vienen sin IVA: el costo se guarda con IVA.'],
    ];
@endphp
<div class="space-y-6" wire:init="cargarProveedores">
    <x-cargando target="nuevas,sincronizar" />
    <x-cargando target="procesar" texto="Leyendo la factura… puede tardar hasta un minuto" />

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="page-title">Cargar factura</h1>
            <p class="page-subtitle">Sacale una foto a la factura del proveedor o adjuntá la imagen o el PDF. Se actualiza el costo y se suma el stock; el precio de venta no se toca.</p>
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

    <section class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span class="step">1</span>
                <div>
                    <h2 class="card-title">Factura</h2>
                    <p class="text-xs text-slate-500">Si tiene varias hojas, agregá una foto por hoja.</p>
                </div>
            </div>
        </div>
        <div class="card-body space-y-4">
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex cursor-pointer items-center gap-3 rounded-md border border-dashed border-slate-300 bg-slate-50 px-4 py-4 hover:border-brand-500 hover:bg-white">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-white ring-1 ring-slate-200">
                        <x-icon name="camera" class="h-5 w-5 text-brand-600" />
                    </span>
                    <span>
                        <span class="block text-sm font-medium text-slate-900">Sacar foto</span>
                        <span class="block text-xs text-slate-500">Abre la cámara del celular</span>
                    </span>
                    <input wire:model="nuevas" type="file" accept="image/*" capture="environment" class="sr-only">
                </label>
                <label class="flex cursor-pointer items-center gap-3 rounded-md border border-dashed border-slate-300 bg-slate-50 px-4 py-4 hover:border-brand-500 hover:bg-white">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-white ring-1 ring-slate-200">
                        <x-icon name="file" class="h-5 w-5 text-slate-500" />
                    </span>
                    <span>
                        <span class="block text-sm font-medium text-slate-900">Adjuntar archivo</span>
                        <span class="block text-xs text-slate-500">Fotos (JPG, PNG) o PDF · hasta 12 MB c/u</span>
                    </span>
                    <input wire:model="nuevas" type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf,.pdf" class="sr-only">
                </label>
            </div>
            @error('nuevas.*') <p class="text-xs text-red-600">{{ $message }}</p> @enderror

            @if($fotos)
                <div class="flex flex-wrap gap-3">
                    @foreach($fotos as $i => $f)
                        @php $esPdf = strtolower($f->getClientOriginalExtension()) === 'pdf'; @endphp
                        <div wire:key="foto-{{ $i }}-{{ $f->getFilename() }}" class="relative h-32 w-24 overflow-hidden rounded-md border border-slate-200 bg-slate-100">
                            @if($esPdf)
                                <div class="flex h-full flex-col items-center justify-center gap-1 p-2 text-center">
                                    <x-icon name="file" class="h-6 w-6 text-slate-400" />
                                    <span class="break-all text-[11px] text-slate-500">{{ \Illuminate\Support\Str::limit($f->getClientOriginalName(), 24) }}</span>
                                </div>
                            @else
                                <img src="{{ $f->temporaryUrl() }}" alt="Hoja {{ $i + 1 }}" class="h-full w-full object-cover">
                            @endif
                            <span class="absolute left-1 top-1 rounded bg-slate-900/70 px-1.5 text-[11px] font-medium text-white">{{ $i + 1 }}</span>
                            <button type="button" wire:click="quitarFoto({{ $i }})" title="Quitar"
                                    class="absolute right-1 top-1 flex h-6 w-6 items-center justify-center rounded-full bg-white/90 text-slate-600 shadow hover:text-red-600">
                                <x-icon name="x" class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section class="card">
        <div class="card-header">
            <div class="flex items-center gap-3">
                <span class="step">2</span>
                <h2 class="card-title">Proveedor e IVA</h2>
            </div>
        </div>
        <div class="card-body grid gap-6 md:grid-cols-2">
            <div>
                @include('partials.elegir-proveedor')
                @unless($proveedorId)
                    <p class="mt-1.5 text-xs text-slate-500">Si lo dejás vacío se busca por el nombre que figura en la factura.</p>
                @endunless
            </div>

            <div>
                <span class="label">IVA de los precios</span>
                <div class="grid grid-cols-2 gap-1 rounded-lg border border-slate-200 bg-white p-1 sm:grid-cols-4">
                    @foreach($opcionesIva as $k => [$texto, $ayuda])
                        <button type="button" wire:click="$set('iva', '{{ $k }}')"
                                class="rounded-md px-3 py-1.5 text-sm font-medium {{ $iva === $k ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50' }}">
                            {{ $texto }}
                        </button>
                    @endforeach
                </div>
                <p class="mt-1.5 text-xs text-slate-500">{{ $opcionesIva[$iva][1] }} Lo podés cambiar después en la revisión.</p>
            </div>
        </div>
        @php $agotado = $tope && $leidasMes >= $tope; @endphp
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-b-lg border-t border-slate-200 bg-slate-50 px-5 py-4">
            <div class="text-sm text-slate-600">
                @if($tope)
                    Facturas leídas este mes:
                    <span class="font-semibold {{ $agotado ? 'text-red-600' : ($leidasMes >= $tope * 0.8 ? 'text-amber-600' : 'text-slate-900') }}">{{ $leidasMes }} / {{ $tope }}</span>
                    <div class="mt-1 h-1.5 w-48 overflow-hidden rounded-full bg-slate-200">
                        <div class="h-full rounded-full {{ $agotado ? 'bg-red-500' : ($leidasMes >= $tope * 0.8 ? 'bg-amber-500' : 'bg-brand-600') }}" style="width: {{ min(100, round($leidasMes / $tope * 100)) }}%"></div>
                    </div>
                @else
                    Facturas leídas este mes: <span class="font-semibold text-slate-900">{{ $leidasMes }}</span>
                @endif
            </div>
            <button wire:click="procesar" @disabled(!$fotos || $agotado) class="btn-primary btn-lg">
                Leer factura
                <x-icon name="arrow-right" />
            </button>
        </div>
    </section>
</div>
