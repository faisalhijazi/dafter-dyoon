@php($money = fn (float $v) => $v > 0 ? $base->format($v) : '—')

<x-layouts::print title="تقرير أعمار الديون" :subtitle="'المبالغ بالعملة الأساسية ('.$base->name.') — كل مبلغ مصنّف حسب عمر الدين غير المسدد'" :back="route('tenant.insights')">
    <x-slot:toolbar>
        <form method="GET" class="text-sm">
            <select name="category" onchange="this.form.submit()" class="rounded-full border border-slate-200 px-3 py-1.5">
                <option value="">كل الأقسام</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected($categoryId === $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </form>
    </x-slot:toolbar>

    <section class="grid grid-cols-5 gap-3 text-center text-sm">
        @foreach ($buckets as $key => $label)
            <div @class(['rounded-2xl p-3', 'bg-emerald-50' => $key === '0-30', 'bg-amber-50' => $key === '31-60', 'bg-orange-50' => $key === '61-90', 'bg-rose-50' => $key === '90+'])>
                <div class="text-xs text-slate-500">{{ $label }}</div>
                <div class="mt-1 font-black tabular-nums">{{ $money($totals[$key]) }}</div>
                <div class="text-[10px] text-slate-400">{{ $total > 0 ? round($totals[$key] / $total * 100) : 0 }}%</div>
            </div>
        @endforeach
        <div class="rounded-2xl bg-slate-900 p-3 text-white">
            <div class="text-xs text-white/70">الإجمالي</div>
            <div class="mt-1 font-black tabular-nums">{{ $base->format($total) }}</div>
            <div class="text-[10px] text-white/60">{{ $rows->count() }} حساب</div>
        </div>
    </section>

    <table class="mt-6 w-full text-right text-sm">
        <thead class="border-b-2 border-slate-900 text-xs text-slate-500">
            <tr>
                <th class="py-2">الحساب</th>
                @foreach ($buckets as $label)
                    <th class="py-2 text-left">{{ $label }}</th>
                @endforeach
                <th class="py-2 text-left">الإجمالي</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($rows as $row)
                <tr>
                    <td class="py-2">
                        <a href="{{ route('tenant.accounts.show', $row['account']) }}" class="font-bold hover:underline">{{ $row['account']->name }}</a>
                        <div class="text-[11px] text-slate-400">أقدم دين غير مسدد: {{ $row['oldest']?->format('Y/m/d') }}</div>
                    </td>
                    @foreach ($buckets as $key => $label)
                        <td @class(['py-2 text-left tabular-nums', 'font-bold text-rose-700' => $key === '90+' && $row['buckets'][$key] > 0, 'text-orange-600' => $key === '61-90' && $row['buckets'][$key] > 0])>{{ $money($row['buckets'][$key]) }}</td>
                    @endforeach
                    <td class="py-2 text-left font-black tabular-nums">{{ $base->format($row['total']) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-8 text-center text-slate-400">لا توجد ديون مستحقة 🎉</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="mt-8 text-xs text-slate-400">تُحسب الأعمار بافتراض أن كل دفعة تسدد أقدم دين أولاً. الديون الأقدم من 90 يوماً هي الأكثر عرضة لعدم التحصيل.</p>
</x-layouts::print>
