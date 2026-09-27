@php
    $status = fn (float $v) => round($v, 2) == 0.0 ? 'متزن' : ($v > 0 ? 'له' : 'عليه');
    $period = $from || $to ? 'الفترة: '.($from?->format('Y/m/d') ?? 'البداية').' — '.($to?->format('Y/m/d') ?? 'اليوم') : 'كل الحركات';
@endphp

<x-layouts::print :title="'كشف حساب: '.$account->name" :subtitle="$period" :back="route('tenant.accounts.show', $account)">
    <x-slot:toolbar>
        <form method="GET" class="flex items-center gap-2 text-sm">
            <input type="date" name="from" value="{{ $from?->format('Y-m-d') }}" class="rounded-full border border-slate-200 px-3 py-1.5" dir="ltr">
            <span class="text-slate-400">—</span>
            <input type="date" name="to" value="{{ $to?->format('Y-m-d') }}" class="rounded-full border border-slate-200 px-3 py-1.5" dir="ltr">
            <button class="rounded-full bg-slate-100 px-4 py-1.5 font-bold">تطبيق</button>
        </form>
    </x-slot:toolbar>

    <section class="grid grid-cols-2 gap-4 text-sm">
        <div class="rounded-2xl bg-slate-50 p-4">
            <div class="text-slate-500">الحساب</div>
            <div class="mt-1 text-lg font-black">{{ $account->name }}</div>
            <div class="text-slate-500">{{ $account->category?->name }}@if ($account->phone) · <span dir="ltr">+{{ $account->phone_code }} {{ $account->phone }}</span>@endif</div>
        </div>
        <div class="rounded-2xl bg-slate-50 p-4">
            <div class="text-slate-500">الرصيد الحالي</div>
            @forelse ($balances as $row)
                <div class="mt-1 text-lg font-black tabular-nums {{ $row['balance'] > 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                    {{ $row['currency']->format(abs($row['balance'])) }} {{ $row['currency']->code }} {{ $status($row['balance']) }}
                </div>
            @empty
                <div class="mt-1 text-lg font-black">متزن</div>
            @endforelse
            @if ($balances->count() > 1)
                <div class="text-xs text-slate-500">الإجمالي: {{ $base->format(abs($total)) }} {{ $base->code }} {{ $status($total) }}</div>
            @endif
        </div>
    </section>

    <table class="mt-6 w-full text-right text-sm">
        <thead class="border-b-2 border-slate-900 text-xs text-slate-500">
            <tr>
                <th class="py-2">التاريخ</th>
                <th class="py-2">البيان</th>
                <th class="py-2 text-left">عليه (مدين)</th>
                <th class="py-2 text-left">له (دائن)</th>
                <th class="py-2 text-left">الرصيد</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($transactions as $tx)
                <tr>
                    <td class="whitespace-nowrap py-2 text-slate-500" dir="ltr">{{ $tx->occurred_at->format('Y/m/d') }}</td>
                    <td class="py-2">
                        {{ $tx->notes ?: ($tx->isCredit() ? 'دفعة / قيد له' : 'قيد عليه') }}
                        @if ($tx->confirmed_at) <span class="text-[10px] font-bold text-emerald-600">✓ مؤكدة</span> @endif
                    </td>
                    <td class="py-2 text-left tabular-nums text-rose-700">{{ $tx->isCredit() ? '' : $tx->currency->format($tx->amount).' '.$tx->currency->code }}</td>
                    <td class="py-2 text-left tabular-nums text-emerald-700">{{ $tx->isCredit() ? $tx->currency->format($tx->amount).' '.$tx->currency->code : '' }}</td>
                    <td class="whitespace-nowrap py-2 text-left font-bold tabular-nums">{{ $tx->currency->format(abs($tx->running_balance)) }} {{ $tx->currency->code }} <span class="text-[10px] font-medium text-slate-500">{{ $status($tx->running_balance) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-8 text-center text-slate-400">لا توجد حركات في هذه الفترة.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="mt-8 text-xs text-slate-400">«له» تعني أن الحساب دائن (دفع أو له مستحقات)، و«عليه» تعني أنه مدين للمتجر.</p>
</x-layouts::print>
