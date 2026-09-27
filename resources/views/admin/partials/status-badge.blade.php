<span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $tenant->isActive() ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
    {{ $tenant->isActive() ? 'نشط' : 'معطّل' }}
</span>
