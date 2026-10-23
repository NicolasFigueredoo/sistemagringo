<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        theme: {
            extend: {
                fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
                colors: {
                    brand: { 50: '#eff6ff', 100: '#dbeafe', 500: '#2563eb', 600: '#1d4ed8', 700: '#1e40af', 900: '#172554' },
                },
            },
        },
    };
</script>
<style type="text/tailwindcss">
    @layer base {
        html { font-size: 16px; -webkit-font-smoothing: antialiased; }
        body { @apply bg-slate-50 text-slate-800; font-feature-settings: 'cv11', 'tnum'; }
        [x-cloak] { display: none !important; }
    }
    @layer components {
        .card { @apply bg-white border border-slate-200 rounded-lg shadow-sm; }
        .card-header { @apply px-5 py-4 border-b border-slate-200 flex items-center justify-between gap-4; }
        .card-title { @apply text-base font-semibold text-slate-900; }
        .card-body { @apply p-5; }
        .label { @apply block text-sm font-medium text-slate-700 mb-1.5; }
        .hint { @apply text-xs text-slate-500 mt-1; }
        .input { @apply block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 shadow-sm
                        focus:outline-none focus:ring-2 focus:ring-brand-500/30 focus:border-brand-500 disabled:bg-slate-50 disabled:text-slate-500; }
        .btn { @apply inline-flex items-center justify-center gap-2 rounded-md px-3.5 py-2 text-sm font-medium transition-colors
                      focus:outline-none focus:ring-2 focus:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap; }
        .btn-primary { @apply btn bg-brand-600 text-white hover:bg-brand-700 focus:ring-brand-500; }
        .btn-secondary { @apply btn bg-white text-slate-700 border border-slate-300 hover:bg-slate-50 focus:ring-slate-300; }
        .btn-success { @apply btn bg-emerald-600 text-white hover:bg-emerald-700 focus:ring-emerald-500; }
        .btn-danger { @apply btn bg-white text-red-600 border border-red-200 hover:bg-red-50 focus:ring-red-300; }
        .btn-ghost { @apply btn text-slate-600 hover:bg-slate-100 hover:text-slate-900; }
        .btn-lg { @apply px-5 py-2.5 text-base; }
        .link { @apply text-sm font-medium text-brand-600 hover:text-brand-700; }
        .badge { @apply inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset; }
        .badge-green { @apply badge bg-emerald-50 text-emerald-700 ring-emerald-600/20; }
        .badge-yellow { @apply badge bg-amber-50 text-amber-800 ring-amber-600/20; }
        .badge-orange { @apply badge bg-orange-50 text-orange-700 ring-orange-600/20; }
        .badge-gray { @apply badge bg-slate-50 text-slate-600 ring-slate-500/20; }
        .badge-red { @apply badge bg-red-50 text-red-700 ring-red-600/20; }
        .badge-blue { @apply badge bg-brand-50 text-brand-700 ring-brand-600/20; }
        .dot { @apply h-1.5 w-1.5 rounded-full; }
        .table { @apply min-w-full divide-y divide-slate-200 text-sm; }
        .table thead th { @apply bg-slate-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500; }
        .table tbody td { @apply px-4 py-3 align-top; }
        .table tbody tr { @apply border-t border-slate-100; }
        .alert { @apply flex items-start gap-3 rounded-md border px-4 py-3 text-sm; }
        .alert-success { @apply alert border-emerald-200 bg-emerald-50 text-emerald-800; }
        .alert-error { @apply alert border-red-200 bg-red-50 text-red-800; }
        .alert-info { @apply alert border-brand-100 bg-brand-50 text-brand-900; }
        .page-title { @apply text-2xl font-semibold tracking-tight text-slate-900; }
        .page-subtitle { @apply mt-1 text-sm text-slate-500; }
        .step { @apply flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700 ring-1 ring-brand-600/20; }
    }
</style>
