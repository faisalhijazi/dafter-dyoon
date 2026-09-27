<?php

use App\Services\CurrencyConverter;
use App\Services\LedgerService;
use App\Services\ReportService;
use App\Support\Palette;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('الإحصائيات')] class extends Component {
    #[Url(except: 12)]
    public int $months = 12;

    public function setMonths(int $months): void
    {
        $this->months = in_array($months, [3, 6, 12], true) ? $months : 12;
    }

    public function with(ReportService $reports, LedgerService $ledger, CurrencyConverter $converter): array
    {
        $flows = $reports->monthlyFlows($this->months);
        $current = end($flows);

        return [
            'flows' => $flows,
            'current' => $current,
            'summary' => $ledger->summary(),
            'top' => $reports->topCustomers(90, 5),
            'base' => $converter->base(),
            'maxFlow' => max(1, ...array_map(fn ($f) => max($f['debts'], $f['collections']), $flows)),
            'positions' => array_column($flows, 'position'),
        ];
    }
}; ?>

@php
    // Chart geometry (SVG user units; the SVG scales to its container).
    $w = 640; $h = 220; $padL = 8; $padR = 8; $padT = 12; $padB = 26;
    $plotW = $w - $padL - $padR; $plotH = $h - $padT - $padB;
    $n = count($flows);
    $slot = $plotW / max(1, $n);
    $bar = min(18, ($slot - 10) / 2);
    $y = fn (float $v) => $padT + $plotH - ($v / $maxFlow) * $plotH;
    // Top-rounded bar anchored to the baseline.
    $barPath = function (float $x, float $v) use ($y, $bar, $padT, $plotH) {
        $top = $y($v); $bottom = $padT + $plotH; $r = min(4, max(0, ($bottom - $top) / 2));
        if ($bottom - $top < 0.5) { return ''; }
        return "M{$x},{$bottom} V".($top + $r)." Q{$x},{$top} ".($x + $r).",{$top} H".($x + $bar - $r)." Q".($x + $bar).",{$top} ".($x + $bar).",".($top + $r)." V{$bottom} Z";
    };
    // Position line.
    $pMin = min(0, ...$positions); $pMax = max(0, ...$positions); $pSpan = max(1, $pMax - $pMin);
    $py = fn (float $v) => $padT + $plotH - (($v - $pMin) / $pSpan) * $plotH;
    $px = fn (int $i) => $padL + $slot * $i + $slot / 2;
    $line = collect($positions)->map(fn ($v, $i) => round($px($i), 1).','.round($py($v), 1))->implode(' ');
    $money = fn (float $v) => $base->format($v);
    $rate = $current['debts'] > 0 ? round($current['collections'] / $current['debts'] * 100) : null;
@endphp

<div class="pb-16">
    <x-dd.hero title="الإحصائيات" subtitle="حركة الديون والتحصيل بالعملة الأساسية" :back="route('dashboard')" icon="presentation-chart-line" />

    <div class="relative -mt-4 space-y-5 px-5">
        {{-- Headline numbers --}}
        <section class="grid grid-cols-2 gap-3">
            <div class="dd-card p-4">
                <p class="text-sm text-slate-500">المحصَّل هذا الشهر</p>
                <p class="mt-1 text-2xl font-black tabular-nums">{{ $money($current['collections']) }}</p>
                <p class="text-xs text-slate-400">{{ $base->code }}</p>
            </div>
            <div class="dd-card p-4">
                <p class="text-sm text-slate-500">ديون جديدة هذا الشهر</p>
                <p class="mt-1 text-2xl font-black tabular-nums">{{ $money($current['debts']) }}</p>
                <p class="text-xs text-slate-400">{{ $base->code }}</p>
            </div>
            <div class="dd-card p-4">
                <p class="text-sm text-slate-500">نسبة التحصيل</p>
                <p class="mt-1 text-2xl font-black tabular-nums">{{ $rate === null ? '—' : $rate.'%' }}</p>
                <p class="text-xs text-slate-400">المحصَّل ÷ الديون الجديدة</p>
            </div>
            <div class="dd-card p-4">
                <p class="text-sm text-slate-500">لك في السوق الآن</p>
                <p class="mt-1 text-2xl font-black tabular-nums">{{ $money($summary['receivable']) }}</p>
                <p class="text-xs text-slate-400">عليك: {{ $money($summary['payable']) }}</p>
            </div>
        </section>

        {{-- Range filter --}}
        <div class="flex justify-center gap-2">
            @foreach ([3 => '3 أشهر', 6 => '6 أشهر', 12 => 'سنة'] as $value => $label)
                <button type="button" wire:click="setMonths({{ $value }})" @class(['dd-chip', 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' => $months === $value, 'bg-white text-slate-500 dark:bg-slate-900' => $months !== $value])>{{ $label }}</button>
            @endforeach
        </div>

        {{-- Debts vs collections per month --}}
        <section class="dd-card p-5" x-data="{ hover: null, flows: @js($flows) }">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-lg font-bold">الديون الجديدة مقابل التحصيل</h2>
                <div class="flex items-center gap-4 text-sm text-slate-600 dark:text-slate-300" aria-hidden="true">
                    <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-[#fb7185] dark:bg-[#dc2640]"></span> ديون جديدة</span>
                    <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-[#047857] dark:bg-[#10ab78]"></span> تحصيل</span>
                </div>
            </div>

            <div class="relative mt-4" dir="ltr">
                <svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full" role="img" aria-label="الديون الجديدة والتحصيل شهرياً">
                    @foreach ([0.25, 0.5, 0.75, 1] as $g)
                        <line x1="{{ $padL }}" x2="{{ $w - $padR }}" y1="{{ $y($maxFlow * $g) }}" y2="{{ $y($maxFlow * $g) }}" class="stroke-slate-100 dark:stroke-slate-800" stroke-width="1" />
                    @endforeach
                    <line x1="{{ $padL }}" x2="{{ $w - $padR }}" y1="{{ $padT + $plotH }}" y2="{{ $padT + $plotH }}" class="stroke-slate-300 dark:stroke-slate-600" stroke-width="1" />

                    @foreach ($flows as $i => $f)
                        @php($x0 = $padL + $slot * $i + ($slot - 2 * $bar - 2) / 2)
                        <g :class="hover !== null && hover !== {{ $i }} ? 'opacity-40' : ''" class="transition-opacity">
                            <path d="{{ $barPath($x0, $f['debts']) }}" class="fill-[#fb7185] dark:fill-[#dc2640]" />
                            <path d="{{ $barPath($x0 + $bar + 2, $f['collections']) }}" class="fill-[#047857] dark:fill-[#10ab78]" />
                        </g>
                        <text x="{{ $padL + $slot * $i + $slot / 2 }}" y="{{ $h - 8 }}" text-anchor="middle" class="fill-slate-400 text-[11px]">{{ $f['label'] }}</text>
                        {{-- Hit target wider than the bars --}}
                        <rect x="{{ $padL + $slot * $i }}" y="0" width="{{ $slot }}" height="{{ $h }}" fill="transparent"
                              x-on:mouseenter="hover = {{ $i }}" x-on:mouseleave="hover = null" x-on:click="hover = hover === {{ $i }} ? null : {{ $i }}" />
                    @endforeach
                </svg>

                <template x-if="hover !== null">
                    <div class="pointer-events-none absolute top-0 z-10 w-44 -translate-x-1/2 rounded-xl bg-white p-3 text-right text-xs shadow-xl ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700"
                         :style="`left: ${Math.min(85, Math.max(15, (hover + 0.5) / flows.length * 100))}%`" dir="rtl">
                        <p class="font-bold" x-text="flows[hover].month"></p>
                        <p class="mt-1 flex justify-between"><span class="text-slate-500">ديون جديدة</span><b class="tabular-nums" x-text="flows[hover].debts.toLocaleString('en', { minimumFractionDigits: 2 })"></b></p>
                        <p class="flex justify-between"><span class="text-slate-500">تحصيل</span><b class="tabular-nums" x-text="flows[hover].collections.toLocaleString('en', { minimumFractionDigits: 2 })"></b></p>
                    </div>
                </template>
            </div>

            <details class="mt-3 text-sm">
                <summary class="cursor-pointer text-slate-500">عرض كجدول</summary>
                <table class="mt-2 w-full text-right">
                    <thead class="text-xs text-slate-400"><tr><th class="py-1">الشهر</th><th class="py-1 text-left">ديون جديدة</th><th class="py-1 text-left">تحصيل</th><th class="py-1 text-left">صافي السوق لك</th></tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach (array_reverse($flows) as $f)
                            <tr><td class="py-1.5">{{ $f['month'] }}</td><td class="py-1.5 text-left tabular-nums">{{ $money($f['debts']) }}</td><td class="py-1.5 text-left tabular-nums">{{ $money($f['collections']) }}</td><td class="py-1.5 text-left tabular-nums">{{ $money($f['position']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        </section>

        {{-- Market position trend (single series) --}}
        <section class="dd-card p-5" x-data="{ hover: null, flows: @js($flows) }">
            <h2 class="text-lg font-bold">صافي ديون السوق (لك − عليك)</h2>
            <p class="text-sm text-slate-500">في نهاية كل شهر. فوق الصفر: السوق مدين لك.</p>
            <div class="relative mt-4" dir="ltr">
                <svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full" role="img" aria-label="صافي ديون السوق شهرياً">
                    <line x1="{{ $padL }}" x2="{{ $w - $padR }}" y1="{{ $py(0) }}" y2="{{ $py(0) }}" class="stroke-slate-300 dark:stroke-slate-600" stroke-width="1" stroke-dasharray="4 4" />
                    <polyline points="{{ $line }}" fill="none" class="stroke-indigo-600 dark:stroke-indigo-400" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                    @foreach ($flows as $i => $f)
                        <text x="{{ $px($i) }}" y="{{ $h - 8 }}" text-anchor="middle" class="fill-slate-400 text-[11px]">{{ $f['label'] }}</text>
                        <circle cx="{{ $px($i) }}" cy="{{ $py($f['position']) }}" r="4" class="fill-indigo-600 stroke-white dark:fill-indigo-400 dark:stroke-slate-900" stroke-width="2" x-show="hover === {{ $i }}" />
                        <rect x="{{ $padL + $slot * $i }}" y="0" width="{{ $slot }}" height="{{ $h }}" fill="transparent"
                              x-on:mouseenter="hover = {{ $i }}" x-on:mouseleave="hover = null" x-on:click="hover = hover === {{ $i }} ? null : {{ $i }}" />
                    @endforeach
                    <line x-show="hover !== null" :x1="{{ $padL }} + {{ $slot }} * hover + {{ $slot / 2 }}" :x2="{{ $padL }} + {{ $slot }} * hover + {{ $slot / 2 }}" y1="{{ $padT }}" y2="{{ $padT + $plotH }}" class="stroke-slate-300 dark:stroke-slate-600" stroke-width="1" />
                </svg>
                <template x-if="hover !== null">
                    <div class="pointer-events-none absolute top-0 z-10 w-40 -translate-x-1/2 rounded-xl bg-white p-3 text-right text-xs shadow-xl ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700"
                         :style="`left: ${Math.min(85, Math.max(15, (hover + 0.5) / flows.length * 100))}%`" dir="rtl">
                        <p class="font-bold" x-text="flows[hover].month"></p>
                        <p class="mt-1 tabular-nums" x-text="Math.abs(flows[hover].position).toLocaleString('en', { minimumFractionDigits: 2 }) + (flows[hover].position >= 0 ? ' لك' : ' عليك')"></p>
                    </div>
                </template>
            </div>
        </section>

        {{-- Most active customers --}}
        <section class="dd-card p-5">
            <h2 class="text-lg font-bold">الأكثر تعاملاً (آخر 90 يوماً)</h2>
            <div class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($top as $row)
                    <a href="{{ route('tenant.accounts.show', $row['account']) }}" wire:navigate class="flex items-center gap-3 py-3">
                        <span class="flex size-10 items-center justify-center rounded-xl {{ Palette::avatar($row['account']->id) }}">{{ $row['account']->initial() }}</span>
                        <span class="flex-1"><span class="block font-bold">{{ $row['account']->name }}</span><span class="text-xs text-slate-400">{{ $row['count'] }} معاملة</span></span>
                        <span class="font-bold tabular-nums">{{ $money($row['volume']) }}</span>
                    </a>
                @empty
                    <p class="py-6 text-center text-slate-400">لا توجد حركات في آخر 90 يوماً.</p>
                @endforelse
            </div>
        </section>

        {{-- Printable reports --}}
        <section class="dd-card divide-y divide-slate-100 px-5 dark:divide-slate-800">
            <x-dd.menu-row :href="route('tenant.reports.aging')" :navigate="false" icon="clock" color="orange" title="تقرير أعمار الديون" subtitle="الديون مصنفة حسب قِدَمها (0–30، 31–60، 61–90، +90 يوماً)" />
            <x-dd.menu-row :href="route('tenant.reports.monthly')" :navigate="false" icon="document-text" color="indigo" title="التقرير الشهري" subtitle="ملخص الشهر: الديون، التحصيل، الوعود وأكبر المديونين" />
            <x-dd.menu-row :href="route('tenant.statement')" icon="chart-pie" color="teal" title="كشف الحسابات العام" subtitle="أرصدة كل الحسابات مع الطباعة" />
        </section>
    </div>
</div>
