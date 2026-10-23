@props(['target', 'texto' => 'Procesando…'])
<div wire:loading.delay.long.flex wire:target="{{ $target }}" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/20 backdrop-blur-[1px]">
    <div class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white px-5 py-4 text-sm font-medium text-slate-700 shadow-lg">
        <x-icon name="loader" class="h-5 w-5 animate-spin text-brand-600" />
        {{ $texto }}
    </div>
</div>
