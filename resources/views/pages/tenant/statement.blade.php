<?php

use App\Models\Account;
use App\Models\Category;
use App\Services\CurrencyConverter;
use App\Services\LedgerService;
use App\Support\Palette;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('كشف الحسابات العام')] class extends Component {
    #[Url(except: '')]
    public string $search = '';
    #[Url(except: '')]
    public string $from = '';
    #[Url(except: '')]
    public string $to = '';
    #[Url(except: null)]
    public ?int $currencyId = null;
    #[Url(except: null)]
    public ?int $categoryId = null;
    #[Url(except: 'all')]
    public string $status = 'all'; // all | receivable (عليه) | payable (له)
    public bool $hideZero = true;
    public bool $showCustomize = false;

    #[Computed]
    public function categories()
    {
        return Category::orderBy('sort_order')->get();
    }

    public function resetFilters(): void
    {
        $this->reset('from', 'to', 'currencyId', 'categoryId', 'status');
        $this->hideZero = true;
    }

    public function with(LedgerService $ledger, CurrencyConverter $converter): array
    {
        $accounts = Account::with('category')
            ->when($this->categoryId, fn ($q) => $q->where('category_id', $this->categoryId))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('phone', 'like', '%'.$this->search.'%')))
            ->orderBy('name')
            ->get();

        $balances = $ledger->balances(
            $accounts->pluck('id')->all(),
            $this->from ? CarbonImmutable::parse($this->from) : null,
            $this->to ? CarbonImmutable::parse($this->to) : null,
            $this->currencyId,
        );

        $rows = $accounts->map(function (Account $account) use ($balances, $converter) {
            $currencies = array_filter($balances[$account->id] ?? [], fn ($v) => round($v, 4) != 0);

            return (object) [
                'account' => $account,
                'currencies' => $currencies,
                'total' => $converter->sumToBase($currencies),
            ];
        })
            ->filter(fn ($r) => ! $this->hideZero || round($r->total, 2) != 0)
            ->filter(fn ($r) => match ($this->status) {
                'receivable' => $r->total < 0,
                'payable' => $r->total > 0,
                default => true,
            })
            ->values();

        $receivable = $rows->where('total', '<', 0)->sum(fn ($r) => -$r->total);
        $payable = $rows->where('total', '>', 0)->sum('total');

        return [
            'rows' => $rows,
            'receivable' => $receivable,
            'payable' => $payable,
            'net' => $receivable - $payable,
            'base' => $converter->base(),
            'converter' => $converter,
            'periodLabel' => $this->from || $this->to ? trim(($this->from ?: '…').' → '.($this->to ?: '…')) : 'كل الأوقات',
            'currencyLabel' => $this->currencyId ? $converter->find($this->currencyId)?->name : 'كل العملات',
            'statusLabel' => ['all' => 'جميع الحسابات', 'receivable' => 'المدينون (عليهم)', 'payable' => 'الدائنون (لهم)'][$this->status] ?? '',
        ];
    }
}; ?>

<div class="pb-16">
    <x-dd.topbar title="كشف الحسابات العام" :back="route('dashboard')">
        <button type="button" onclick="window.print()" class="dd-icon-btn size-11 text-rose-600" aria-label="طباعة / PDF" title="طباعة / حفظ PDF">
            <flux:icon.document-arrow-down variant="solid" class="size-8" />
        </button>
    </x-dd.topbar>

    {{-- Print-only header --}}
    <div class="hidden px-5 pb-4 print:block">
        <h1 class="text-2xl font-bold">{{ auth()->user()->tenant->name }} — كشف الحسابات العام</h1>
        <p class="text-sm text-slate-500">{{ now()->format('Y/m/d H:i') }} · {{ $periodLabel }} · {{ $currencyLabel }} · {{ $statusLabel }}</p>
    </div>

    <div class="space-y-5 px-5">
        <div class="no-print flex gap-3">
            <div class="relative flex-1">
                <flux:icon.magnifying-glass class="pointer-events-none absolute right-4 top-1/2 size-6 -translate-y-1/2 text-slate-500" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="بحث بالاسم أو رقم الهاتف..." class="dd-input rounded-3xl py-4 pr-14 shadow-sm">
            </div>
            <button type="button" wire:click="$set('showCustomize', true)" class="dd-btn bg-teal-600 px-6 text-white shadow-lg shadow-teal-600/20"><flux:icon.adjustments-horizontal variant="mini" /> تخصيص</button>
        </div>

        <div class="no-print no-scrollbar flex gap-2 overflow-x-auto">
            <span class="dd-chip border border-slate-200 bg-white font-medium text-slate-600 dark:border-slate-700 dark:bg-slate-900"><flux:icon.calendar-days variant="micro" /> {{ $periodLabel }}</span>
            <span class="dd-chip border border-slate-200 bg-white font-medium text-slate-600 dark:border-slate-700 dark:bg-slate-900"><flux:icon.circle-stack variant="micro" /> {{ $currencyLabel }}</span>
            <span class="dd-chip border border-slate-200 bg-white font-medium text-slate-600 dark:border-slate-700 dark:bg-slate-900"><flux:icon.scale variant="micro" /> {{ $statusLabel }}</span>
        </div>

        @unless ($currencyId)
            <p class="flex items-start gap-3 rounded-3xl border border-amber-300 bg-amber-50 p-4 font-medium text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-amber-100 dark:bg-amber-500/20"><flux:icon.information-circle variant="mini" /></span>
                تنبيه: تم احتساب إجمالي الأرصدة وتحويلها بالعملة الافتراضية ({{ $base->name }}) بناءً على أسعار الصرف المسجلة.
            </p>
        @endunless

        <section class="dd-card p-5">
            <div class="flex items-center gap-3">
                <span class="flex size-11 items-center justify-center rounded-xl bg-teal-50 text-teal-600 dark:bg-teal-500/10"><flux:icon.chart-pie variant="solid" /></span>
                <h2 class="flex-1 text-xl font-bold">المركز المالي الإجمالي</h2>
                <span class="dd-badge bg-teal-50 text-teal-700 dark:bg-teal-500/10 dark:text-teal-300">{{ $rows->count() }} حساباً</span>
            </div>
            <div class="mt-4 grid grid-cols-3 gap-2 rounded-2xl bg-slate-50 p-4 text-center dark:bg-slate-800/60">
                <div>
                    <p class="flex items-center justify-center gap-1 text-slate-500"><flux:icon.arrow-up variant="micro" class="text-emerald-500" /> لك</p>
                    <p class="mt-1 text-xl font-bold tabular-nums text-emerald-600">{{ number_format($receivable, 2) }}</p>
                </div>
                <div>
                    <p class="flex items-center justify-center gap-1 text-slate-500"><flux:icon.arrow-down variant="micro" class="text-rose-500" /> عليك</p>
                    <p class="mt-1 text-xl font-bold tabular-nums text-rose-600">{{ number_format($payable, 2) }}</p>
                </div>
                <div>
                    <p><span @class(['dd-badge text-xs', 'bg-emerald-100 text-emerald-700' => $net >= 0, 'bg-rose-100 text-rose-600' => $net < 0])>{{ $net >= 0 ? 'لك' : 'عليك' }}</span></p>
                    <p @class(['mt-1 text-xl font-bold tabular-nums', 'text-emerald-600' => $net >= 0, 'text-rose-600' => $net < 0])>{{ number_format(abs($net), 2) }} <span class="text-sm">{{ $currencyId ? $converter->find($currencyId)?->code : $base->code }}</span></p>
                </div>
            </div>
        </section>

        @forelse ($rows as $row)
            @php($t = $row->total)
            @php($code = $currencyId ? $converter->find($currencyId)?->code : $base->code)
            <a href="{{ route('tenant.accounts.show', $row->account) }}" wire:navigate wire:key="st-{{ $row->account->id }}" class="dd-card block p-5">
                <div class="flex items-center gap-4">
                    <span class="flex size-14 shrink-0 items-center justify-center rounded-full bg-teal-50 text-2xl text-teal-700 dark:bg-teal-500/10 dark:text-teal-300">{{ $row->account->initial() }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xl font-bold">{{ $row->account->name }}</p>
                        <span class="dd-badge mt-1 bg-slate-100 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $row->account->category?->name }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        @if (round($t, 2) != 0)
                            <span @class(['dd-badge text-xs', 'bg-emerald-50 text-emerald-700' => $t > 0, 'bg-rose-50 text-rose-600' => $t < 0])>{{ $t > 0 ? 'له' : 'عليه' }}</span>
                        @endif
                        <span @class(['text-xl font-bold tabular-nums', 'text-emerald-600' => $t > 0, 'text-rose-600' => $t < 0, 'text-slate-500' => round($t, 2) == 0])>{{ number_format(abs($t), 2) }} {{ $code }}</span>
                        <flux:icon.chevron-left variant="mini" class="no-print text-slate-400" />
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 px-3 py-2 text-sm text-slate-500 dark:bg-slate-800/60">
                    <span class="flex items-center gap-1"><flux:icon.calendar variant="micro" /> تاريخ آخر دفعة: {{ $row->account->last_activity_at?->format('Y/m/d') ?? '—' }}</span>
                    @if (count($row->currencies) > 1 || (! $currencyId && count($row->currencies) === 1 && ! $converter->find(array_key_first($row->currencies))?->is_base))
                        <span class="text-xs" dir="rtl">
                            @foreach ($row->currencies as $cid => $amount)
                                {{ $converter->find($cid)?->code }}: {{ $converter->find($cid)?->format($amount) }}@if (! $loop->last) | @endif
                            @endforeach
                        </span>
                    @endif
                </div>
            </a>
        @empty
            <p class="py-16 text-center text-slate-500">لا توجد حسابات مطابقة للتصفية.</p>
        @endforelse
    </div>

    <x-dd.sheet model="showCustomize" title="تخصيص الكشف">
        <div class="space-y-5">
            <div class="grid grid-cols-2 gap-3">
                <div><label class="dd-label">من تاريخ</label><input type="date" wire:model.live="from" class="dd-input" dir="ltr"></div>
                <div><label class="dd-label">إلى تاريخ</label><input type="date" wire:model.live="to" class="dd-input" dir="ltr"></div>
            </div>
            <div>
                <label class="dd-label">العملة</label>
                <select wire:model.live="currencyId" class="dd-input">
                    <option value="">كل العملات (محوّلة للعملة الأساسية)</option>
                    @foreach ($converter->currencies() as $cur)
                        <option value="{{ $cur->id }}">{{ $cur->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="dd-label">القسم</label>
                <select wire:model.live="categoryId" class="dd-input">
                    <option value="">كل الأقسام</option>
                    @foreach ($this->categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="grid grid-cols-3 gap-2">
                @foreach (['all' => 'الكل', 'receivable' => 'عليهم', 'payable' => 'لهم'] as $key => $label)
                    <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['dd-btn py-2.5', 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' => $status === $key, 'bg-slate-100 text-slate-500 dark:bg-slate-800' => $status !== $key])>{{ $label }}</button>
                @endforeach
            </div>
            <label class="flex items-center gap-3">
                <input type="checkbox" wire:model.live="hideZero" class="size-5 rounded">
                إخفاء الحسابات المتزنة (رصيد صفري)
            </label>
            <div class="grid grid-cols-2 gap-3">
                <button type="button" wire:click="resetFilters" class="dd-btn-ghost">إعادة تعيين</button>
                <button type="button" wire:click="$set('showCustomize', false)" class="dd-btn-primary">عرض</button>
            </div>
        </div>
    </x-dd.sheet>
</div>
