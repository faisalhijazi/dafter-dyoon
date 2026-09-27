<?php

use App\Models\Account;
use App\Models\BackupLog;
use App\Models\Category;
use App\Models\PaymentPromise;
use App\Models\PaymentReport;
use App\Models\Tag;
use App\Services\CollectionsService;
use App\Services\CurrencyConverter;
use App\Services\CustomerScore;
use App\Services\DebtLimitService;
use App\Services\LedgerService;
use App\Support\Palette;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('الرئيسية')] class extends Component {
    #[Url(except: 'all')]
    public string $tab = 'all';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: null)]
    public ?int $tag = null;

    public bool $showSearch = false;

    public int $limit = 30;

    public function mount(): void
    {
        $this->showSearch = $this->search !== '';
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->limit = 30;
    }

    public function setTag(?int $tag = null): void
    {
        $this->tag = $this->tag === $tag ? null : $tag;
        $this->limit = 30;
    }

    #[Computed]
    public function tags()
    {
        return Tag::whereHas('accounts')->withCount('accounts')->orderBy('name')->get();
    }

    public function loadMore(): void
    {
        $this->limit += 30;
    }

    #[Computed]
    public function categories()
    {
        return Category::withCount('accounts')->orderBy('sort_order')->get();
    }

    private function scopedQuery(): Builder
    {
        return Account::query()
            ->when($this->tab !== 'all', fn ($q) => $q->where('category_id', (int) $this->tab))
            ->when($this->tag, fn ($q) => $q->whereHas('tags', fn ($t) => $t->whereKey($this->tag)))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('phone', 'like', '%'.$this->search.'%')));
    }

    #[Computed]
    public function accounts()
    {
        return $this->scopedQuery()
            ->with('category', 'tags')
            ->orderByRaw('pinned_at IS NULL, pinned_at DESC') // pinned customers first
            ->orderByRaw('last_activity_at IS NULL, last_activity_at DESC')
            ->latest('id')
            ->take($this->limit + 1)
            ->get();
    }

    public function with(LedgerService $ledger, CurrencyConverter $converter, DebtLimitService $limits, CustomerScore $scores, CollectionsService $collections): array
    {
        $tenant = auth()->user()->tenant;
        $accounts = $this->accounts->take($this->limit);
        $balances = $ledger->balances($accounts->pluck('id')->all());
        $due = $collections->dueAccounts();

        // The floating bar reflects the whole selected tab, not just the loaded page.
        $tabIds = $this->tab === 'all' && $this->search === '' ? null : $this->scopedQuery()->pluck('id')->all();

        return [
            'rows' => $accounts,
            'hasMore' => $this->accounts->count() > $this->limit,
            'balances' => $balances,
            'summary' => $ledger->summary($tabIds),
            'base' => $converter->base(),
            'converter' => $converter,
            'limits' => $limits,
            'tenant' => $tenant,
            'tabName' => $this->tab === 'all' ? 'الكل' : ($this->categories->firstWhere('id', (int) $this->tab)?->name ?? 'الكل'),
            'scores' => $scores->forAccounts($accounts, $balances),
            'alerts' => [
                'due' => $due->whereIn('state', ['overdue', 'today'])->count(),
                'promises' => PaymentPromise::query()->pending()->whereDate('promised_on', '<=', today())->count(),
                'reports' => PaymentReport::query()->where('status', PaymentReport::PENDING)->count(),
                // Remind to back up when the last backup is over a week old (only for those who may).
                'backup' => auth()->user()->can('manage-settings') && $tenant->transactions()->exists()
                    && ! BackupLog::query()->where('action', 'backup')->where('created_at', '>=', now()->subDays(7))->exists(),
            ],
        ];
    }
}; ?>

<div class="pb-72">
    {{-- Header --}}
    <header class="flex items-center gap-4 px-5 pb-2 pt-6">
        <div class="min-w-0 flex-1">
            <h1 class="sr-only">{{ config('app.name') }}</h1>
            <x-brand.logo class="h-11" />
            <p class="mt-1 flex items-center gap-1.5 text-sm font-medium"><span class="size-1.5 rounded-full bg-slate-900 dark:bg-white"></span>{{ $tabName }}</p>
        </div>
        <button type="button" wire:click="$toggle('showSearch')" class="dd-icon-btn size-14 bg-blue-50 text-blue-500 dark:bg-blue-500/10" aria-label="بحث">
            <flux:icon.magnifying-glass class="size-7" />
        </button>
        <a href="{{ route('tenant.assistant') }}" wire:navigate class="dd-icon-btn size-14 bg-linear-to-br from-violet-500 to-blue-500 text-white shadow-lg shadow-indigo-500/30" aria-label="مساعد AI" title="مساعد AI">
            <flux:icon.sparkles variant="solid" class="size-7" />
        </a>
        <button type="button" x-on:click="$dispatch('open-drawer')" class="dd-icon-btn size-14 bg-violet-100 text-violet-500 dark:bg-violet-500/10" aria-label="القائمة">
            <flux:icon.bars-3 class="size-7" />
        </button>
    </header>

    @if ($showSearch)
        <div class="px-5 pt-3">
            <div class="relative">
                <flux:icon.magnifying-glass class="pointer-events-none absolute right-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" />
                <input type="search" wire:model.live.debounce.300ms="search" autofocus placeholder="بحث بالاسم أو رقم الهاتف..." class="dd-input pr-12" />
            </div>
        </div>
    @endif

    {{-- Alerts: collections, customer payments, backup reminder --}}
    @if ($alerts['due'] || $alerts['promises'] || $alerts['reports'] || $alerts['backup'])
        <div class="no-scrollbar mt-4 flex gap-2 overflow-x-auto px-5">
            @if ($alerts['due'] || $alerts['promises'])
                <a href="{{ route('tenant.collections') }}" wire:navigate class="dd-chip shrink-0 bg-rose-50 text-rose-700 ring-1 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30">
                    <flux:icon.bell-alert variant="micro" /> {{ $alerts['due'] + $alerts['promises'] }} مستحق للتحصيل اليوم
                </a>
            @endif
            @if ($alerts['reports'])
                <a href="{{ route('tenant.collections', ['tab' => 'reports']) }}" wire:navigate class="dd-chip shrink-0 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30">
                    <flux:icon.banknotes variant="micro" /> {{ $alerts['reports'] }} دفعة من زبائن بانتظار تأكيدك
                </a>
            @endif
            @if ($alerts['backup'])
                <span x-data="ddDismissToday('dd-backup-dismissed')" x-show="! hidden" class="dd-chip shrink-0 bg-amber-50 text-amber-800 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">
                    <a href="{{ route('tenant.backup') }}" wire:navigate class="flex items-center gap-1.5"><flux:icon.cloud-arrow-up variant="micro" /> لم تأخذ نسخة احتياطية منذ أسبوع — انسخ الآن</a>
                    <button type="button" x-on:click="dismiss()" class="mr-1 opacity-60" aria-label="إخفاء اليوم">✕</button>
                </span>
            @endif
        </div>
    @endif

    {{-- Category tabs --}}
    <nav class="no-scrollbar mt-4 flex gap-8 overflow-x-auto border-b border-transparent px-8">
        @foreach ([['id' => 'all', 'name' => 'الكل', 'count' => null], ...$this->categories->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name, 'count' => $c->accounts_count])] as $item)
            <button type="button" wire:click="setTab('{{ $item['id'] }}')" @class([
                'relative shrink-0 pb-3 pt-2 text-lg transition',
                'font-bold text-slate-900 dark:text-white' => $tab === $item['id'],
                'text-slate-500' => $tab !== $item['id'],
            ])>
                {{ $item['name'] }}
                @if ($item['count'])
                    <span class="mr-1 rounded-full bg-slate-200/70 px-1.5 text-xs text-slate-500 dark:bg-slate-800">{{ $item['count'] }}</span>
                @endif
                @if ($tab === $item['id'])
                    <span class="absolute inset-x-0 -bottom-px h-1 rounded-full bg-slate-900 dark:bg-white"></span>
                @endif
            </button>
        @endforeach
    </nav>

    @if ($this->tags->isNotEmpty())
        <div class="no-scrollbar mt-3 flex gap-2 overflow-x-auto px-5">
            @foreach ($this->tags as $t)
                <button type="button" wire:click="setTag({{ $t->id }})" @class([
                    'dd-chip shrink-0 px-3 py-1.5',
                    'bg-slate-900 text-white dark:bg-white dark:text-slate-900' => $tag === $t->id,
                    Palette::badge($t->color) => $tag !== $t->id,
                ])># {{ $t->name }} <span class="opacity-60">{{ $t->accounts_count }}</span></button>
            @endforeach
        </div>
    @endif

    {{-- Accounts feed --}}
    <div class="space-y-5 px-5 pt-6" wire:loading.class="opacity-60" wire:target="setTab,search">
        @forelse ($rows as $account)
            @php
                $accountBalances = array_filter($balances[$account->id] ?? [], fn ($v) => round($v, 4) != 0);
                $baseTotal = $converter->sumToBase($accountBalances);
                $limit = $limits->status($account, $tenant, $baseTotal);
                $categoryColor = $account->category?->color ?? 'slate';
            @endphp
            <a href="{{ route('tenant.accounts.show', $account) }}" wire:navigate wire:key="acc-{{ $account->id }}" class="dd-card block p-6 transition hover:-translate-y-0.5 hover:shadow-lg">
                <div class="flex items-start gap-4">
                    <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl text-3xl font-medium shadow-lg {{ Palette::avatar($account->id) }}">{{ $account->initial() }}</span>
                    <div class="min-w-0 flex-1">
                        <h2 class="flex items-center gap-2 text-2xl font-bold">
                            <span class="truncate">{{ $account->name }}</span>
                            @if ($account->isPinned())
                                <flux:icon.bookmark variant="solid" class="size-5 shrink-0 text-amber-500" />
                            @endif
                            @if ($score = $scores[$account->id] ?? null)
                                <span @class(['shrink-0 rounded-full px-2 py-0.5 text-xs font-bold',
                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $score['color'] === 'emerald',
                                    'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300' => $score['color'] === 'sky',
                                    'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $score['color'] === 'amber',
                                    'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $score['color'] === 'rose',
                                ]) title="تقييم السداد: {{ $score['score'] }}/100{{ $score['reasons'] ? ' — '.implode('، ', $score['reasons']) : '' }}">{{ $score['label'] }}</span>
                            @endif
                        </h2>
                        <div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
                            @if ($account->category)
                                <span class="dd-badge {{ Palette::badge($categoryColor) }}"><flux:icon.folder variant="micro" /> {{ $account->category->name }}</span>
                            @endif
                            <span class="flex items-center gap-1 text-slate-400">
                                <flux:icon.clock variant="micro" />
                                {{ $account->last_activity_at?->diffForHumans() ?? 'لا توجد حركات' }}
                            </span>
                            @if ($account->due_date && $baseTotal < 0 && $account->due_date->lte(today()))
                                <span class="dd-badge bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300"><flux:icon.bell-alert variant="micro" /> {{ $account->due_date->isToday() ? 'مستحق اليوم' : 'متأخر عن موعد السداد' }}</span>
                            @endif
                            @foreach ($account->tags as $accountTag)
                                <span class="dd-badge {{ Palette::badge($accountTag->color) }} text-xs"># {{ $accountTag->name }}</span>
                            @endforeach
                            @if ($limit['state'] === 'exceeded')
                                <span class="dd-badge bg-rose-600 text-white"><flux:icon.exclamation-triangle variant="micro" /> تجاوز السقف</span>
                            @elseif ($limit['state'] === 'warning')
                                <span class="dd-badge bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400"><flux:icon.exclamation-triangle variant="micro" /> يقترب من السقف</span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="mt-5 space-y-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                    @forelse ($accountBalances as $currencyId => $amount)
                        @php($currency = $converter->find($currencyId))
                        <div class="flex items-center gap-3">
                            @if ($amount > 0)
                                <span class="flex size-12 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10"><flux:icon.arrow-up variant="mini" /></span>
                            @else
                                <span class="flex size-12 items-center justify-center rounded-xl bg-rose-50 text-rose-500 dark:bg-rose-500/10"><flux:icon.arrow-down variant="mini" /></span>
                            @endif
                            <span class="dd-badge bg-slate-100 px-3 py-1.5 text-base text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $currency?->code }}</span>
                            <span class="flex-1"></span>
                            <span @class(['text-2xl font-bold tabular-nums', 'text-emerald-600' => $amount > 0, 'text-rose-600' => $amount < 0])>{{ $currency?->format(abs($amount)) }}</span>
                            @if ($amount > 0)
                                <span class="dd-badge border border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-400">له</span>
                            @else
                                <span class="dd-badge border border-rose-200 bg-rose-50 text-rose-600 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-400">عليه</span>
                            @endif
                        </div>
                    @empty
                        <div class="flex items-center gap-3">
                            <span class="flex size-12 items-center justify-center rounded-xl bg-slate-100 text-slate-500 dark:bg-slate-800"><flux:icon.check variant="mini" /></span>
                            <span class="dd-badge bg-slate-100 px-3 py-1.5 text-base text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $base?->code }}</span>
                            <span class="flex-1"></span>
                            <span class="text-2xl font-bold tabular-nums text-slate-500">{{ $base?->format(0) }}</span>
                            <span class="dd-badge border border-slate-300 bg-slate-100 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">متزن</span>
                        </div>
                    @endforelse
                </div>
            </a>
        @empty
            <div class="flex flex-col items-center py-20 text-center">
                <span class="flex size-28 items-center justify-center rounded-full bg-slate-200/60 text-slate-400 dark:bg-slate-800"><flux:icon.users variant="solid" class="size-12" /></span>
                <h2 class="mt-6 text-xl font-bold">{{ $search ? 'لا توجد نتائج مطابقة' : 'لا توجد حسابات بعد' }}</h2>
                <p class="mt-2 text-slate-500">أضف أول عميل أو مورد لتبدأ تسجيل الديون.</p>
                <a href="{{ route('tenant.accounts.create') }}" wire:navigate class="dd-btn-primary mt-6"><flux:icon.user-plus variant="mini" /> إضافة شخص</a>
            </div>
        @endforelse

        @if ($hasMore)
            <button type="button" wire:click="loadMore" class="dd-btn-ghost w-full">عرض المزيد</button>
        @endif
    </div>

    {{-- Floating balance bar --}}
    <div class="fixed inset-x-0 bottom-0 z-30 mx-auto max-w-3xl rounded-t-[2rem] bg-white/95 px-5 pb-5 pt-5 shadow-[0_-8px_30px_-12px_rgb(15_23_42/0.25)] backdrop-blur dark:bg-slate-900/95">
        <div class="grid grid-cols-2 gap-4">
            <div class="flex items-center gap-3 rounded-3xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-500/20 dark:bg-emerald-500/10">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-500/20"><flux:icon.arrow-up variant="mini" /></span>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-emerald-700 dark:text-emerald-400">لك</p>
                    <p class="truncate text-xl font-bold tabular-nums text-emerald-700 dark:text-emerald-400">{{ number_format($summary['receivable'], 2) }} <span class="text-sm">{{ $base?->code }}</span></p>
                </div>
            </div>
            <div class="flex items-center gap-3 rounded-3xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-500/20 dark:bg-rose-500/10">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-500 dark:bg-rose-500/20"><flux:icon.arrow-down variant="mini" /></span>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-rose-600 dark:text-rose-400">عليك</p>
                    <p class="truncate text-xl font-bold tabular-nums text-rose-600 dark:text-rose-400">{{ number_format($summary['payable'], 2) }} <span class="text-sm">{{ $base?->code }}</span></p>
                </div>
            </div>
        </div>

        <div class="mt-4 flex items-center gap-3">
            <a href="{{ route('tenant.quick-entry') }}" wire:navigate class="dd-icon-btn size-16 shrink-0 {{ Palette::tile('dark') }} shadow-xl" aria-label="الإدخال السريع">
                <flux:icon.bolt variant="solid" class="size-7" />
            </a>
            @php($net = $summary['net'])
            <div @class([
                'flex min-w-0 flex-1 items-center justify-center gap-3 rounded-3xl border px-4 py-3',
                'border-emerald-200 bg-emerald-50 dark:border-emerald-500/20 dark:bg-emerald-500/10' => $net > 0,
                'border-rose-200 bg-rose-50 dark:border-rose-500/20 dark:bg-rose-500/10' => $net < 0,
                'border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800' => $net == 0,
            ])>
                <span @class(['flex size-11 shrink-0 items-center justify-center rounded-xl text-white shadow', $net >= 0 ? 'bg-emerald-500' : 'bg-rose-600'])>
                    <flux:icon :name="$net >= 0 ? 'arrow-trending-up' : 'arrow-trending-down'" variant="mini" />
                </span>
                <div class="min-w-0 text-center">
                    <p class="text-sm text-slate-500">الصافي ({{ $base?->code }})</p>
                    <p @class(['flex items-center gap-2 text-xl font-bold tabular-nums', 'text-emerald-600' => $net > 0, 'text-rose-600' => $net < 0])>
                        {{ number_format(abs($net), 2) }}
                        @if ($net != 0)
                            <span @class(['dd-badge py-0.5 text-xs', 'bg-emerald-100 text-emerald-700' => $net > 0, 'bg-rose-100 text-rose-600' => $net < 0])>{{ $net > 0 ? 'لك' : 'عليك' }}</span>
                        @endif
                    </p>
                </div>
            </div>
            <button type="button" x-on:click="$dispatch('open-sheet', { name: 'advanced' })" class="dd-icon-btn size-16 shrink-0 bg-slate-300 text-white dark:bg-slate-700" aria-label="إجراءات متقدمة">
                <flux:icon.ellipsis-horizontal class="size-8" />
            </button>
        </div>
    </div>

    <x-dd.sheet name="advanced" title="إجراءات متقدمة">
        <div class="divide-y divide-slate-100 dark:divide-slate-800">
            <x-dd.menu-row :href="route('tenant.accounts.create')" icon="user-plus" color="amber" title="إضافة شخص" subtitle="إضافة شخص جديد إلى حساباتك" />
            <x-dd.menu-row :href="route('tenant.transfer')" icon="arrows-right-left" color="blue" title="تحويل بسيط (بين الحسابات)" subtitle="تحويل مبلغ من حساب إلى حساب آخر" />
            <x-dd.menu-row :href="route('tenant.split')" icon="receipt-percent" color="emerald" title="تقسيم فاتورة / حساب" subtitle="تقسيم مبلغ على عدة أشخاص" />
            <x-dd.menu-row :href="route('tenant.quick-entry')" icon="bolt" color="indigo" title="الإدخال السريع" subtitle="تسجيل عملية لأي شخص بخطوة واحدة" />
            <x-dd.menu-row :href="route('tenant.collections')" icon="banknotes" color="rose" title="التحصيل" subtitle="المستحقات، وعود السداد ودفعات الزبائن" />
            @can('view-reports')
                <x-dd.menu-row :href="route('tenant.insights')" icon="presentation-chart-line" color="violet" title="الإحصائيات والتقارير" subtitle="رسوم بيانية، أعمار الديون والتقرير الشهري" />
                <x-dd.menu-row :href="route('tenant.statement')" icon="chart-pie" color="teal" title="كشف الحسابات العام" subtitle="تقرير أرصدة ومطالبات شامل مع الطباعة" />
            @endcan
        </div>
    </x-dd.sheet>
</div>
