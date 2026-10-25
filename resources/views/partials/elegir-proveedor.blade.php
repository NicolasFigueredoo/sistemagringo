{{-- Selector de proveedor con búsqueda. Necesita $proveedores, $proveedorId y $nuevoProveedor en el componente. --}}
@php $seleccionado = $proveedorId && $proveedorId !== 'nuevo' ? $proveedores->firstWhere('id', $proveedorId) : null; @endphp
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
