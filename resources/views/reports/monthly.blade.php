@php
    $r = $report;
    $money = fn (float $v) => $base->format($v).' '.$base->code;
    $change = function (float $now, float $before) {
        if ($before <= 0) {
            return null;
        }
        $pct = round(($now - $before) / $before * 100);

        return ($pct >= 0 ? '▲ ' : '▼ ').abs($pct).'% عن الشهر السابق';
    };
    $rate = $r['debts'] > 0 ? min(999, round($r['collections'] / $r['debts'] * 100)) : null;
@endphp

<x-layouts::print :title="'التقرير الشهري — '.$r['month']->translatedFormat('F Y')" subtitle="ملخص الديون والتحصيل خلال الشهر" :back="route('tenant.insights')">
    <x-slot:toolbar>
        <form method="GET" class="text-sm">
            <select name="month" onchange="this.form.submit()" class="rounded-full border border-slate-200 px-3 py-1.5">
                @foreach ($months as $m)
                    <option value="{{ $m->format('Y-m') }}" @selected($m->format('Y-m') === $r['month']->format('Y-m'))>{{ $m->translatedFormat('F Y') }}</option>
                @endforeach
            </select>
        </form>
    </x-slot:toolbar>

    <section class="grid grid-cols-3 gap-3 text-sm">
        <div class="rounded-2xl bg-rose-50 p-4">
            <div class="text-slate-500">ديون جديدة (عليهم)</div>
            <div class="mt-1 text-xl font-black tabular-nums text-rose-700">{{ $money($r['debts']) }}</div>
            <div class="text-xs text-slate-400">{{ $change($r['debts'], $r['previous_debts']) }}</div>
        </div>
        <div class="rounded-2xl bg-emerald-50 p-4">
            <div class="text-slate-500">المحصَّل (لهم)</div>
            <div class="mt-1 text-xl font-black tabular-nums text-emerald-700">{{ $money($r['collections']) }}</div>
            <div class="text-xs text-slate-400">{{ $change($r['collections'], $r['previous_collections']) }}</div>
        </div>
        <div class="rounded-2xl bg-slate-50 p-4">
            <div class="text-slate-500">نسبة التحصيل</div>
            <div class="mt-1 text-xl font-black tabular-nums">{{ $rate === null ? '—' : $rate.'%' }}</div>
            <div class="text-xs text-slate-400">المحصَّل مقابل الديون الجديدة</div>
        </div>
    </section>

    <section class="mt-4 grid grid-cols-4 gap-3 text-center text-sm">
        <div class="rounded-2xl border border-slate-200 p-3"><div class="text-xs text-slate-500">المعاملات</div><div class="mt-1 text-lg font-black">{{ number_format($r['transactions']) }}</div></div>
        <div class="rounded-2xl border border-slate-200 p-3"><div class="text-xs text-slate-500">حسابات جديدة</div><div class="mt-1 text-lg font-black">{{ number_format($r['new_accounts']) }}</div></div>
        <div class="rounded-2xl border border-slate-200 p-3"><div class="text-xs text-slate-500">وعود مُلتزم بها</div><div class="mt-1 text-lg font-black text-emerald-700">{{ $r['promises']['kept'] }}</div></div>
        <div class="rounded-2xl border border-slate-200 p-3"><div class="text-xs text-slate-500">وعود مُخلفة</div><div class="mt-1 text-lg font-black text-rose-700">{{ $r['promises']['broken'] }}</div></div>
    </section>

    <section class="mt-6 grid grid-cols-2 gap-6">
        <div>
            <h2 class="mb-2 font-black">الموقف في نهاية الشهر</h2>
            <dl class="divide-y divide-slate-100 rounded-2xl border border-slate-200 px-4 text-sm">
                <div class="flex justify-between py-2.5"><dt class="text-slate-500">لك في السوق</dt><dd class="font-bold tabular-nums text-emerald-700">{{ $money($r['receivable']) }}</dd></div>
                <div class="flex justify-between py-2.5"><dt class="text-slate-500">عليك</dt><dd class="font-bold tabular-nums text-rose-700">{{ $money($r['payable']) }}</dd></div>
                <div class="flex justify-between py-2.5"><dt class="text-slate-500">الصافي</dt><dd class="font-black tabular-nums">{{ $money(abs($r['receivable'] - $r['payable'])) }} {{ $r['receivable'] >= $r['payable'] ? 'لك' : 'عليك' }}</dd></div>
            </dl>
        </div>
        <div>
            <h2 class="mb-2 font-black">أكبر المديونين</h2>
            <ol class="divide-y divide-slate-100 rounded-2xl border border-slate-200 px-4 text-sm">
                @forelse ($r['top_debtors'] as $i => $row)
                    <li class="flex justify-between py-2.5"><span>{{ $i + 1 }}. {{ $row['name'] }}</span><span class="font-bold tabular-nums text-rose-700">{{ $money($row['owed']) }}</span></li>
                @empty
                    <li class="py-4 text-center text-slate-400">لا توجد ديون 🎉</li>
                @endforelse
            </ol>
        </div>
    </section>

    <p class="mt-8 text-xs text-slate-400">المبالغ محوّلة إلى {{ $base->name }} حسب أسعار الصرف الحالية.</p>
</x-layouts::print>
