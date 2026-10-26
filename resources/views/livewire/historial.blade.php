<div class="space-y-6">
    <x-cargando target="deshacer" />

    <div>
        <h1 class="page-title">Historial</h1>
        <p class="page-subtitle">Todas las cargas de precios. Las aplicadas se pueden deshacer y volver a los precios anteriores.</p>
    </div>

    @if($mensaje)
        <div class="alert-success"><x-icon name="check" class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ $mensaje }}</span></div>
    @endif
    @if($error)
        <div class="alert-error"><x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ $error }}</span></div>
    @endif

    <div class="card overflow-x-auto">
        <table class="table">
            <thead>
                <tr>
                    <th>Carga</th>
                    <th>Fecha</th>
                    <th class="!text-right">Renglones</th>
                    <th>Estado</th>
                    <th class="!text-right"></th>
                </tr>
            </thead>
            <tbody>
            @forelse($cargas as $c)
                <tr wire:key="c-{{ $c->id }}">
                    <td class="!align-middle">
                        <div class="font-medium text-slate-900">{{ $c->titulo }}</div>
                        <div class="max-w-xs truncate text-xs text-slate-500">{{ $c->archivo }}</div>
                    </td>
                    <td class="!align-middle whitespace-nowrap text-slate-600">{{ $c->created_at->format('d/m/Y H:i') }}</td>
                    <td class="!align-middle text-right tabular-nums text-slate-600">{{ number_format($c->items_count, 0, ',', '.') }}</td>
                    <td class="!align-middle whitespace-nowrap">
                        @if($c->estado === 'revision')
                            <span class="badge-yellow"><span class="dot bg-amber-500"></span>Sin aplicar</span>
                        @elseif($c->estado === 'aplicada')
                            <span class="badge-green"><span class="dot bg-emerald-500"></span>{{ $c->aplicados_count }} actualizados</span>
                            <div class="mt-0.5 text-xs text-slate-400">{{ $c->aplicada_at?->format('d/m H:i') }}</div>
                        @elseif($c->estado === 'aplicando')
                            <span class="badge-blue"><span class="dot bg-brand-500"></span>Actualizando {{ $c->progreso_hecho }} de {{ $c->progreso_total }}</span>
                        @else
                            <span class="badge-gray"><span class="dot bg-slate-400"></span>Deshecha</span>
                            <div class="mt-0.5 text-xs text-slate-400">{{ $c->deshecha_at?->format('d/m H:i') }}</div>
                        @endif
                    </td>
                    <td class="!align-middle">
                        <div class="flex justify-end gap-2">
                            @if($c->estado === 'revision')
                                <a href="{{ route('revisar', $c) }}" class="btn-primary px-3 py-1.5 text-xs">Continuar</a>
                                <button wire:click="eliminar({{ $c->id }})" wire:confirm="¿Borrar esta carga? No modifica Odoo." class="btn-ghost px-3 py-1.5 text-xs">Borrar</button>
                            @elseif($c->estado === 'aplicada')
                                <a href="{{ route('revisar', $c) }}" class="btn-secondary px-3 py-1.5 text-xs">Ver</a>
                                <button wire:click="deshacer({{ $c->id }})"
                                        wire:confirm="Se van a restaurar en Odoo los precios que había antes de esta carga. ¿Confirmás?"
                                        class="btn-danger px-3 py-1.5 text-xs"><x-icon name="undo" class="h-3.5 w-3.5" /> Deshacer</button>
                            @else
                                <a href="{{ route('revisar', $c) }}" class="btn-secondary px-3 py-1.5 text-xs">Ver</a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="!py-12 text-center text-sm text-slate-500">Todavía no hay cargas.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $cargas->links() }}
</div>
