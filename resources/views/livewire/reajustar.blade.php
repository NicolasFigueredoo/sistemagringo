<div class="space-y-6">
    <x-cargando target="crear" texto="Leyendo los costos de Odoo…" />

    <div>
        <h1 class="page-title">Reajustar precios</h1>
        <p class="page-subtitle">Toma el costo actual de cada producto en Odoo y le suma la ganancia de su categoría. Sin IVA: costo $ 1.000 con 50% = venta $ 1.500.</p>
    </div>

    @if($error)
        <div class="alert-error"><x-icon name="alert" class="mt-0.5 h-4 w-4 shrink-0" /><span>{{ $error }}</span></div>
    @endif

    @if($pendiente)
        <div class="alert-info">
            <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0" />
            <span>Hay un reajuste sin aplicar del {{ $pendiente->created_at->format('d/m H:i') }}.
                <a href="{{ route('revisar', $pendiente) }}" class="link">Continuarlo</a></span>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach([['Categorías con ganancia', $categorias, 'text-brand-600'], ['Productos a reajustar', $productos, 'text-emerald-600'], ['Productos sin ganancia (no se tocan)', $sinMargen, 'text-slate-500']] as [$t, $n, $c])
            <div class="card px-4 py-3">
                <div class="text-xs font-medium text-slate-500">{{ $t }}</div>
                <div class="mt-1 text-2xl font-semibold {{ $c }}">{{ number_format($n, 0, ',', '.') }}</div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-body flex flex-wrap items-center justify-between gap-4">
            <p class="text-sm text-slate-600">Se arma la lista para revisar: nada cambia en Odoo hasta que toques <b>Actualizar en Odoo</b>. Solo se cambia el precio de venta, el costo no se toca.</p>
            <button wire:click="crear" @disabled($productos === 0) class="btn-primary btn-lg">
                <x-icon name="zap" /> Calcular precios de venta
            </button>
        </div>
    </div>

    <p class="text-xs text-slate-500">La ganancia de cada categoría se carga en <a href="{{ route('categorias') }}" class="link">Categorías</a>.</p>
</div>
