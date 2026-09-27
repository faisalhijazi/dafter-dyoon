@if ($tenant->plan)
    <span @class([
        'rounded-full px-2.5 py-1 text-xs font-medium',
        'bg-slate-100 text-slate-600' => $tenant->plan->isFree(),
        'bg-amber-100 text-amber-700' => $tenant->isPro(),
        'bg-rose-100 text-rose-700' => ! $tenant->plan->isFree() && ! $tenant->isPro(),
    ])>{{ $tenant->plan->name }}@if (! $tenant->plan->isFree() && ! $tenant->isPro()) (منتهية)@endif</span>
@else
    <span class="text-slate-400">—</span>
@endif
