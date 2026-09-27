<?php

use App\Models\PaymentPromise;
use App\Models\PaymentReport;
use App\Services\CollectionsService;
use App\Services\CurrencyConverter;
use App\Services\LedgerService;
use App\Services\PaymentReportService;
use App\Support\Palette;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('التحصيل')] class extends Component {
    #[Url(except: 'due')]
    public string $tab = 'due';

    public function mount(CollectionsService $collections): void
    {
        $collections->breakOverduePromises();

        // Land on pending payment reports when there are some and nothing is due.
        if (! request()->has('tab') && $this->reports->isNotEmpty() && $this->due->isEmpty()) {
            $this->tab = 'reports';
        }
    }

    #[Computed]
    public function due()
    {
        return app(CollectionsService::class)->dueAccounts();
    }

    #[Computed]
    public function promises()
    {
        return PaymentPromise::with('account', 'currency')
            ->where(fn ($q) => $q->where('status', PaymentPromise::PENDING)
                ->orWhere('resolved_at', '>=', now()->subDays(30)))
            ->orderByRaw("status = 'pending' DESC")
            ->orderBy('promised_on')
            ->get();
    }

    #[Computed]
    public function reports()
    {
        return PaymentReport::with('account', 'currency')->where('status', PaymentReport::PENDING)->latest()->get();
    }

    public function approve(int $id, PaymentReportService $service): void
    {
        try {
            $service->approve(PaymentReport::findOrFail($id));
            $this->dispatch('toast', message: 'تم تسجيل الدفعة في دفتر الزبون.');
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage(), type: 'error');
        }

        unset($this->reports, $this->due, $this->promises);
    }

    public function reject(int $id, PaymentReportService $service): void
    {
        $service->reject(PaymentReport::findOrFail($id));
        unset($this->reports);
        $this->dispatch('toast', message: 'تم رفض الدفعة.');
    }

    public function cancelPromise(int $id): void
    {
        PaymentPromise::whereKey($id)->where('status', PaymentPromise::PENDING)
            ->update(['status' => PaymentPromise::CANCELLED, 'resolved_at' => now()]);
        unset($this->promises);
    }

    public function with(CollectionsService $collections, LedgerService $ledger, CurrencyConverter $converter): array
    {
        $today = CarbonImmutable::today();
        $pending = $this->promises->where('status', PaymentPromise::PENDING);
        $balances = $ledger->balances($pending->pluck('account_id')->unique()->all());

        return [
            'base' => $converter->base(),
            'collections' => $collections,
            'owedFor' => fn ($accountId) => -$converter->sumToBase($balances[$accountId] ?? []),
            'counts' => [
                'overdue' => $this->due->where('state', 'overdue')->count(),
                'today' => $this->due->where('state', 'today')->count() + $pending->filter(fn ($p) => $p->promised_on->isSameDay($today))->count(),
                'promises' => $pending->count(),
                'reports' => $this->reports->count(),
            ],
        ];
    }
}; ?>

@php($money = fn (float $v) => $base->format($v).' '.$base->code)
<div class="pb-16">
    <x-dd.hero title="التحصيل" subtitle="المستحقات، وعود السداد والدفعات المُبلغ عنها" :back="route('dashboard')" icon="banknotes" />

    <div class="relative -mt-4 space-y-5 px-5">
        <section class="grid grid-cols-4 gap-2 text-center">
            @foreach ([['متأخر', $counts['overdue'], 'text-rose-600'], ['اليوم', $counts['today'], 'text-amber-600'], ['وعود قائمة', $counts['promises'], 'text-indigo-600'], ['دفعات للمراجعة', $counts['reports'], 'text-emerald-600']] as [$label, $value, $tone])
                <div class="dd-card p-3">
                    <p class="text-2xl font-black {{ $tone }}">{{ $value }}</p>
                    <p class="text-xs text-slate-500">{{ $label }}</p>
                </div>
            @endforeach
        </section>

        <div class="grid grid-cols-3 gap-1.5 rounded-2xl bg-white p-1.5 shadow-sm dark:bg-slate-900">
            @foreach (['due' => 'المستحقات', 'promises' => 'الوعود', 'reports' => 'دفعات مُبلغ عنها'] as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['relative rounded-xl py-2.5 text-sm font-bold', 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' => $tab === $key, 'text-slate-500' => $tab !== $key])>
                    {{ $label }}
                    @if ($key === 'reports' && $counts['reports'])
                        <span class="absolute -top-1 left-2 flex size-5 items-center justify-center rounded-full bg-emerald-500 text-[10px] text-white">{{ $counts['reports'] }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        {{-- Due dates --}}
        @if ($tab === 'due')
            @forelse ($this->due as $row)
                @php($account = $row['account'])
                <div wire:key="due-{{ $account->id }}" class="dd-card p-4">
                    <div class="flex items-center gap-3">
                        <a href="{{ route('tenant.accounts.show', $account) }}" wire:navigate class="flex size-12 shrink-0 items-center justify-center rounded-2xl text-xl {{ Palette::avatar($account->id) }}">{{ $account->initial() }}</a>
                        <a href="{{ route('tenant.accounts.show', $account) }}" wire:navigate class="min-w-0 flex-1">
                            <p class="truncate text-lg font-bold">{{ $account->name }}</p>
                            <p class="text-sm">
                                @if ($row['state'] === 'overdue')
                                    <span class="font-bold text-rose-600">متأخر {{ abs($row['days']) }} يوم</span>
                                @elseif ($row['state'] === 'today')
                                    <span class="font-bold text-amber-600">مستحق اليوم</span>
                                @else
                                    <span class="text-slate-500">مستحق بعد {{ $row['days'] }} يوم</span>
                                @endif
                                <span class="text-slate-400">· {{ $row['due']->format('Y/m/d') }}{{ $account->due_repeat !== 'none' ? ' · '.\App\Models\Account::DUE_REPEATS[$account->due_repeat] : '' }}</span>
                            </p>
                        </a>
                        <span class="text-left font-black tabular-nums text-rose-600">{{ $money($row['owed']) }}</span>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <a href="{{ $collections->reminderLink($account, $row['owed']) }}" target="_blank" rel="noopener" class="dd-btn bg-[#25D366] py-2 text-sm text-white">تذكير واتساب</a>
                        <a href="{{ route('tenant.accounts.show', [$account, 'promise' => 1]) }}" wire:navigate class="dd-btn border border-slate-200 py-2 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300">سجّل وعد سداد</a>
                    </div>
                </div>
            @empty
                <div class="py-14 text-center">
                    <p class="text-4xl">✅</p>
                    <p class="mt-3 font-bold">لا توجد مستحقات خلال الأسبوع القادم</p>
                    <p class="mt-1 text-sm text-slate-500">حدّد «موعد السداد» من صفحة تعديل الحساب ليظهر هنا ويذكّرك في موعده.</p>
                </div>
            @endforelse
        @endif

        {{-- Promises --}}
        @if ($tab === 'promises')
            @forelse ($this->promises as $promise)
                @php($pendingPromise = $promise->status === 'pending')
                <div wire:key="pr-{{ $promise->id }}" @class(['dd-card p-4', 'opacity-70' => ! $pendingPromise])>
                    <div class="flex items-center gap-3">
                        <span @class(['flex size-10 shrink-0 items-center justify-center rounded-xl text-lg',
                            'bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10' => $pendingPromise,
                            'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10' => $promise->status === 'kept',
                            'bg-rose-50 text-rose-600 dark:bg-rose-500/10' => $promise->status === 'broken',
                            'bg-slate-100 text-slate-400 dark:bg-slate-800' => $promise->status === 'cancelled'])>
                            {{ ['pending' => '⏳', 'kept' => '✓', 'broken' => '✗', 'cancelled' => '—'][$promise->status] }}
                        </span>
                        <a href="{{ route('tenant.accounts.show', $promise->account) }}" wire:navigate class="min-w-0 flex-1">
                            <p class="truncate font-bold">{{ $promise->account->name }}</p>
                            <p class="text-sm text-slate-500">{{ $promise->describe() }}@if ($promise->notes) — {{ $promise->notes }}@endif</p>
                        </a>
                        <span @class(['dd-badge text-xs',
                            'bg-indigo-50 text-indigo-700' => $pendingPromise && ! $promise->promised_on->isToday(),
                            'bg-amber-100 text-amber-700' => $pendingPromise && $promise->promised_on->isToday(),
                            'bg-emerald-50 text-emerald-700' => $promise->status === 'kept',
                            'bg-rose-50 text-rose-700' => $promise->status === 'broken',
                            'bg-slate-100 text-slate-500' => $promise->status === 'cancelled'])>
                            {{ $pendingPromise ? ($promise->promised_on->isToday() ? 'اليوم' : $promise->promised_on->diffForHumans()) : ['kept' => 'التزم', 'broken' => 'أخلف', 'cancelled' => 'أُلغي'][$promise->status] }}
                        </span>
                    </div>
                    @if ($pendingPromise)
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <a href="{{ $collections->reminderLink($promise->account, max(0, $owedFor($promise->account_id)), $promise) }}" target="_blank" rel="noopener" class="dd-btn bg-[#25D366] py-2 text-sm text-white">تذكير بالوعد</a>
                            <button type="button" wire:click="cancelPromise({{ $promise->id }})" wire:confirm="إلغاء هذا الوعد؟" class="dd-btn border border-slate-200 py-2 text-sm text-slate-500 dark:border-slate-700">إلغاء الوعد</button>
                        </div>
                    @endif
                </div>
            @empty
                <div class="py-14 text-center">
                    <p class="text-4xl">🤝</p>
                    <p class="mt-3 font-bold">لا توجد وعود سداد</p>
                    <p class="mt-1 text-sm text-slate-500">عندما يقول الزبون «بدفع يوم الخميس» سجّل الوعد من دفتره، وسيُعلَّم تلقائياً «التزم» أو «أخلف».</p>
                </div>
            @endforelse
        @endif

        {{-- Payment reports from the statement link --}}
        @if ($tab === 'reports')
            @forelse ($this->reports as $report)
                @php($cur = $report->currency ?? $base)
                <div wire:key="rep-{{ $report->id }}" class="dd-card p-4">
                    <div class="flex items-start gap-3">
                        <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10"><flux:icon.banknotes variant="solid" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-bold">{{ $report->account->name }} <span class="font-medium text-slate-500">يقول إنه دفع</span></p>
                            <p class="mt-1 text-xl font-black tabular-nums text-emerald-700 dark:text-emerald-400">{{ $cur->format($report->amount) }} {{ $cur->code }}</p>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ $report->methodLabel() }}@if ($report->reference) · مرجع: <span dir="ltr">{{ $report->reference }}</span>@endif
                                @if ($report->payer_name) · باسم {{ $report->payer_name }}@endif
                                · {{ $report->created_at->diffForHumans() }}
                            </p>
                            @if ($report->notes)
                                <p class="mt-1 rounded-xl bg-slate-50 p-2 text-sm dark:bg-slate-800">{{ $report->notes }}</p>
                            @endif
                            @if ($report->receipt_path)
                                <a href="{{ route('tenant.payment-reports.receipt', $report) }}" target="_blank" class="mt-2 inline-flex items-center gap-1 text-sm font-bold text-indigo-600"><flux:icon.photo variant="micro" /> عرض إيصال الدفع</a>
                            @endif
                        </div>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button type="button" wire:click="approve({{ $report->id }})" wire:confirm="تأكيد استلام {{ $cur->format($report->amount) }} {{ $cur->code }} من {{ $report->account->name }}؟ ستُسجَّل «له» في دفتره." class="dd-btn bg-emerald-600 py-2 text-sm text-white">✓ وصلتني، سجّلها</button>
                        <button type="button" wire:click="reject({{ $report->id }})" wire:confirm="رفض هذه الدفعة؟" class="dd-btn border border-rose-200 py-2 text-sm text-rose-600 dark:border-rose-500/30">لم تصلني</button>
                    </div>
                </div>
            @empty
                <div class="py-14 text-center">
                    <p class="text-4xl">📥</p>
                    <p class="mt-3 font-bold">لا توجد دفعات بانتظار المراجعة</p>
                    <p class="mt-1 text-sm text-slate-500">يستطيع الزبون الإبلاغ عن تحويل أو دفعة من رابط كشف حسابه الحي، وتظهر هنا لتؤكدها بضغطة.</p>
                </div>
            @endforelse
        @endif
    </div>
</div>
