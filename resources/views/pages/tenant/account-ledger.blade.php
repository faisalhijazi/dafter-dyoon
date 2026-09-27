<?php

use App\Models\Account;
use App\Models\Currency;
use App\Models\PaymentPromise;
use App\Models\StatementDispute;
use App\Models\Transaction;
use App\Services\ActivityLogger;
use App\Services\CurrencyConverter;
use App\Services\CustomerScore;
use App\Services\DebtLimitService;
use App\Services\LedgerService;
use App\Services\StatementMessage;
use App\Services\WhatsAppMessage;
use App\Support\MathExpression;
use App\Support\Palette;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::tenant')] class extends Component {
    use WithFileUploads;

    public Account $account;

    // List / filters
    public bool $showSearch = false;
    public string $search = '';
    #[Url(as: 'currency', except: null)]
    public ?int $currencyFilter = null;
    public ?int $viewCurrencyId = null;
    public bool $showFilter = false;

    // Add / edit entry sheet
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $amount = '';
    public ?int $currency_id = null;
    public string $notes = '';
    public string $date = '';
    public $photo = null;
    public ?array $limitWarning = null;

    // Options, multi-select and move
    public bool $showOptions = false;
    public ?int $selectedId = null;
    public bool $selecting = false;
    public array $selected = [];
    public bool $showMove = false;
    public string $moveSearch = '';

    // Currency exchange sheet
    public bool $showExchange = false;
    public string $exDirection = 'buy';
    public ?int $exCurrencyId = null;
    public string $exAmount = '';
    public string $exRate = '';
    public string $exNotes = '';
    public string $exDate = '';
    public $exPhoto = null;

    // Live statement sharing + customer disputes
    public bool $showShare = false;
    public bool $showDisputes = false;

    // Payment promise ("بدفع الخميس")
    public bool $showPromise = false;
    public string $promiseDate = '';
    public string $promiseAmount = '';
    public ?int $promiseCurrencyId = null;
    public string $promiseNotes = '';

    public function mount(Account $account): void
    {
        $this->account = $account;

        // Coming from «التحصيل» → «سجّل وعد سداد».
        if (request()->boolean('promise')) {
            $this->openPromise();
        }
        // Default to the account's last currency, unless the plan no longer allows it.
        $this->currency_id = $this->currencies->firstWhere('id', $this->lastCurrencyId())?->id
            ?? app(CurrencyConverter::class)->base()?->id;
    }

    private function lastCurrencyId(): ?int
    {
        return $this->account->transactions()->latest('occurred_at')->value('currency_id');
    }

    #[Computed]
    public function currencies()
    {
        return app(CurrencyConverter::class)->usable();
    }

    /** Editing, moving and deleting entries is the "delete & edit" staff permission. */
    private function authorizeChanges(): void
    {
        abort_unless(auth()->user()->can('delete-records'), 403);
    }

    public function togglePin(): void
    {
        $this->account->update(['pinned_at' => $this->account->pinned_at ? null : now()]);
        $this->dispatch('toast', message: $this->account->pinned_at ? 'تم تثبيت الحساب أعلى القائمة.' : 'تم إلغاء التثبيت.');
    }

    public function openPromise(): void
    {
        $this->resetValidation();
        $this->promiseDate = now()->addDays(3)->format('Y-m-d');
        $this->promiseAmount = '';
        $this->promiseNotes = '';
        $this->promiseCurrencyId = $this->currency_id ?? app(CurrencyConverter::class)->base()?->id;
        $this->showPromise = true;
    }

    public function savePromise(ActivityLogger $logger): void
    {
        $data = $this->validate([
            'promiseDate' => ['required', 'date', 'after_or_equal:today'],
            'promiseAmount' => ['nullable', 'numeric', 'gt:0'],
            'promiseCurrencyId' => ['required', 'in:'.$this->currencies->pluck('id')->implode(',')],
            'promiseNotes' => ['nullable', 'string', 'max:255'],
        ], attributes: ['promiseDate' => 'تاريخ الوعد', 'promiseAmount' => 'المبلغ']);

        $promise = PaymentPromise::create([
            'account_id' => $this->account->id,
            'currency_id' => $data['promiseCurrencyId'],
            'user_id' => auth()->id(),
            'amount' => $data['promiseAmount'] !== '' ? $data['promiseAmount'] : null,
            'promised_on' => $data['promiseDate'],
            'notes' => $data['promiseNotes'] ?: null,
        ]);

        $logger->log('promise.created', "سجّل وعد سداد من {$this->account->name}: ".$promise->load('currency')->describe(), $this->account);
        $this->showPromise = false;
        $this->dispatch('toast', message: 'تم تسجيل الوعد. سيُعلَّم «التزم» تلقائياً عند وصول الدفعة.');
    }

    // ---- Entry form -----------------------------------------------------

    public function openCreate(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'amount', 'notes', 'photo', 'limitWarning');
        $this->date = now()->format('Y-m-d');
        $this->currency_id ??= $this->currencies->first()?->id;
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $this->authorizeChanges();
        $tx = $this->account->transactions()->findOrFail($id);
        $this->resetValidation();
        $this->reset('photo', 'limitWarning');
        $this->editingId = $tx->id;
        $this->amount = rtrim(rtrim(number_format((float) $tx->amount, 4, '.', ''), '0'), '.');
        $this->currency_id = $tx->currency_id;
        $this->notes = (string) $tx->notes;
        $this->date = $tx->occurred_at->format('Y-m-d');
        $this->showOptions = false;
        $this->showForm = true;
    }

    public function save(string $type, bool $force = false): void
    {
        $ledger = app(LedgerService::class);
        $limits = app(DebtLimitService::class);
        $converter = app(CurrencyConverter::class);
        $tenant = auth()->user()->tenant;

        abort_unless(in_array($type, [Transaction::CREDIT, Transaction::DEBIT], true), 422);

        if ($this->editingId) {
            $this->authorizeChanges();
        }

        $value = MathExpression::evaluate($this->amount);
        $this->validate([
            'amount' => ['required', function ($attr, $v, $fail) use ($value) {
                if ($value === null || $value <= 0) {
                    $fail('أدخل مبلغاً صحيحاً أكبر من صفر.');
                }
            }],
            'currency_id' => ['required', 'integer', 'in:'.$this->currencies->pluck('id')->implode(',')],
            'notes' => ['nullable', 'string', 'max:1000'],
            'date' => ['required', 'date', 'before_or_equal:'.now()->addYear()->format('Y-m-d')],
            'photo' => ['nullable', 'image', 'max:5120'],
        ], attributes: ['amount' => 'المبلغ', 'currency_id' => 'العملة', 'date' => 'التاريخ', 'photo' => 'الصورة']);

        $currency = $converter->find($this->currency_id);

        if (! $this->editingId && ! $tenant->withinLimit('transactions')) {
            $this->dispatch('toast', message: 'وصلت للحد الأقصى من المعاملات في خطتك. قم بالترقية للمتابعة.', type: 'error');

            return;
        }

        // Debt ceiling check (only when adding a new debit).
        if ($type === Transaction::DEBIT && ! $this->editingId && ! $force) {
            $baseBalance = $ledger->accountBaseBalance($this->account);
            if ($limits->wouldExceed($this->account, $tenant, $baseBalance, $converter->toBase($value, $currency))) {
                $this->limitWarning = [
                    'type' => $type,
                    'limit' => $limits->limitFor($this->account, $tenant),
                    'after' => max(0, -($baseBalance - $converter->toBase($value, $currency))),
                ];

                return;
            }
        }

        $occurredAt = CarbonImmutable::parse($this->date)->setTimeFrom(now());
        $path = $this->photo?->store('attachments/'.$tenant->id, 'local');

        if ($this->editingId) {
            $tx = $this->account->transactions()->findOrFail($this->editingId);
            $tx->update([
                'type' => $type,
                'amount' => $value,
                'currency_id' => $currency->id,
                'exchange_rate' => $tx->currency_id === $currency->id ? $tx->exchange_rate : $currency->exchange_rate,
                'notes' => $this->notes ?: null,
                'occurred_at' => $tx->occurred_at->format('Y-m-d') === $this->date ? $tx->occurred_at : $occurredAt,
                'attachment_path' => $path ?? $tx->attachment_path,
            ]);
            $ledger->touchActivity($this->account);
            $message = 'تم تعديل العملية.';
        } else {
            $ledger->record($this->account, $type, $value, $currency, $this->notes ?: null, $occurredAt, $path);
            $message = ($type === Transaction::CREDIT ? 'له ' : 'عليه ').$currency->format($value).' '.$currency->code;
        }

        $this->showForm = false;
        $this->reset('editingId', 'amount', 'notes', 'photo', 'limitWarning');
        $this->viewCurrencyId = $currency->id;
        $this->dispatch('toast', message: $message);
    }

    // ---- Options, selection, move, delete -------------------------------

    public function openOptions(int $id): void
    {
        if ($this->selecting) {
            $this->toggleSelect($id);

            return;
        }

        $this->selectedId = $id;
        $this->showOptions = true;
    }

    #[Computed]
    public function selectedTransaction(): ?Transaction
    {
        return $this->selectedId ? $this->account->transactions()->with('currency', 'account')->find($this->selectedId) : null;
    }

    public function startSelecting(): void
    {
        $this->authorizeChanges();
        $this->selecting = true;
        $this->selected = $this->selectedId ? [$this->selectedId] : [];
        $this->showOptions = false;
    }

    public function toggleSelect(int $id): void
    {
        $this->selected = in_array($id, $this->selected)
            ? array_values(array_diff($this->selected, [$id]))
            : [...$this->selected, $id];
    }

    public function cancelSelecting(): void
    {
        $this->reset('selecting', 'selected');
    }

    public function openMove(): void
    {
        $this->authorizeChanges();
        if (! $this->selecting) {
            $this->selected = array_filter([$this->selectedId]);
        }

        $this->showOptions = false;
        $this->moveSearch = '';
        $this->showMove = true;
    }

    #[Computed]
    public function moveTargets()
    {
        return Account::whereKeyNot($this->account->id)
            ->when($this->moveSearch !== '', fn ($q) => $q->where('name', 'like', '%'.$this->moveSearch.'%'))
            ->orderBy('name')->take(30)->get();
    }

    public function moveTo(int $accountId, LedgerService $ledger): void
    {
        $this->authorizeChanges();
        $target = Account::findOrFail($accountId);
        $ids = $this->account->transactions()->whereIn('id', $this->selected)->pluck('id')->all();
        $count = $ledger->move($ids, $target);
        $ledger->touchActivity($this->account);

        $this->reset('showMove', 'selecting', 'selected', 'selectedId');
        $this->dispatch('toast', message: "تم نقل {$count} معاملة إلى {$target->name}.");
    }

    public function deleteTransaction(?int $id = null): void
    {
        $this->authorizeChanges();
        $ids = $id ? [$id] : $this->selected;
        $count = $this->account->transactions()->whereIn('id', $ids)->get()->each->delete()->count();
        app(LedgerService::class)->touchActivity($this->account);

        $this->reset('showOptions', 'selecting', 'selected', 'selectedId');
        $this->dispatch('toast', message: "تم نقل {$count} معاملة إلى سلة المحذوفات.");
    }

    // ---- Currency exchange -----------------------------------------------

    public function openExchange(): void
    {
        // Exchange needs at least one usable foreign currency (the free plan has the dollar).
        if ($this->currencies->where('is_base', false)->isEmpty()) {
            $this->redirectRoute('tenant.upgrade', navigate: true);

            return;
        }

        $this->resetValidation();
        $foreign = $this->currencies->where('is_base', false)->first();
        $this->exCurrencyId = $foreign?->id;
        $this->exRate = $foreign ? (string) (float) $foreign->exchange_rate : '';
        $this->reset('exAmount', 'exNotes', 'exPhoto');
        $this->exDirection = 'buy';
        $this->exDate = now()->format('Y-m-d');
        $this->showExchange = true;
    }

    public function updatedExCurrencyId(): void
    {
        $currency = app(CurrencyConverter::class)->find((int) $this->exCurrencyId);
        $this->exRate = $currency ? (string) (float) $currency->exchange_rate : '';
    }

    public function saveExchange(LedgerService $ledger, CurrencyConverter $converter): void
    {
        $amount = MathExpression::evaluate($this->exAmount);

        $this->validate([
            'exCurrencyId' => ['required', 'in:'.$this->currencies->where('is_base', false)->pluck('id')->implode(',')],
            'exAmount' => ['required', fn ($a, $v, $fail) => ($amount === null || $amount <= 0) ? $fail('أدخل مبلغاً صحيحاً.') : null],
            'exRate' => ['required', 'numeric', 'gt:0'],
            'exDirection' => ['required', 'in:buy,sell'],
            'exDate' => ['required', 'date'],
            'exNotes' => ['nullable', 'string', 'max:500'],
            'exPhoto' => ['nullable', 'image', 'max:5120'],
        ], attributes: ['exCurrencyId' => 'العملة', 'exAmount' => 'المبلغ', 'exRate' => 'سعر الصرف']);

        $currency = $converter->find((int) $this->exCurrencyId);
        $path = $this->exPhoto?->store('attachments/'.auth()->user()->tenant_id, 'local');

        $ledger->exchange($this->account, $this->exDirection, $currency, $amount, (float) $this->exRate, $this->exNotes ?: null,
            CarbonImmutable::parse($this->exDate)->setTimeFrom(now()), $path);

        $this->showExchange = false;
        $this->dispatch('toast', message: 'تم تنفيذ عملية الصرف.');
    }

    // ---- Live statement ---------------------------------------------------

    public function enableStatementLink(): void
    {
        abort_unless(auth()->user()->tenant->canUse('live_statement'), 403);

        $this->account->regenerateStatementToken();
        $this->dispatch('toast', message: 'تم تفعيل رابط كشف الحساب.');
    }

    public function regenerateStatementLink(): void
    {
        abort_unless(auth()->user()->tenant->canUse('live_statement'), 403);

        $this->account->regenerateStatementToken();
        $this->dispatch('toast', message: 'تم تجديد الرابط، ولم يعد الرابط القديم يعمل.');
    }

    public function disableStatementLink(): void
    {
        $this->account->disableStatementLink();
        $this->dispatch('toast', message: 'تم إيقاف رابط كشف الحساب.');
    }

    public function resolveDispute(int $id): void
    {
        $this->account->disputes()->findOrFail($id)->update(['status' => StatementDispute::RESOLVED, 'resolved_at' => now()]);
        $this->dispatch('toast', message: 'تم إغلاق الاعتراض.');
    }

    public function reopenDispute(int $id): void
    {
        $this->account->disputes()->findOrFail($id)->update(['status' => StatementDispute::OPEN, 'resolved_at' => null]);
    }

    public function setViewCurrency(int $id): void
    {
        $this->viewCurrencyId = $id;
    }

    public function filterCurrency(?int $id = null): void
    {
        $this->currencyFilter = $id;
        $this->showFilter = false;
    }

    public function with(LedgerService $ledger, CurrencyConverter $converter, DebtLimitService $limits): array
    {
        $statement = $ledger->statement($this->account, $this->currencyFilter, $this->search ?: null);
        $balances = $ledger->accountBalances($this->account);
        $used = $this->account->transactions()->distinct()->pluck('currency_id');

        $viewId = $this->currencyFilter ?? $this->viewCurrencyId ?? $this->lastCurrencyId() ?? $converter->base()?->id;
        $viewTx = $this->account->transactions()->where('currency_id', $viewId);
        $baseBalance = $converter->sumToBase($balances);

        return [
            'transactions' => $statement,
            'count' => $this->account->transactions()->count(),
            'usedCurrencies' => $used->map(fn ($id) => $converter->find($id))->filter()->values(),
            'viewCurrency' => $converter->find($viewId) ?? $converter->base(),
            'totalCredit' => (float) (clone $viewTx)->where('type', Transaction::CREDIT)->sum('amount'),
            'totalDebit' => (float) (clone $viewTx)->where('type', Transaction::DEBIT)->sum('amount'),
            'net' => $balances[$viewId] ?? 0.0,
            'base' => $converter->base(),
            'baseBalance' => $baseBalance,
            'limit' => $limits->status($this->account, auth()->user()->tenant, $baseBalance),
            'converter' => $converter,
            'whatsapp' => app(WhatsAppMessage::class),
            'liveStatement' => auth()->user()->tenant->canUse('live_statement'),
            'openDisputes' => $this->account->disputes()->open()->count(),
            'disputes' => $this->showDisputes ? $this->account->disputes()->with('transaction.currency')->latest()->take(50)->get() : collect(),
            'statementText' => $this->showShare ? app(StatementMessage::class)->text($this->account) : '',
            'statementTextNoLink' => $this->showShare ? app(StatementMessage::class)->text($this->account, withLink: false) : '',
            'score' => app(CustomerScore::class)->forAccounts([$this->account])[$this->account->id] ?? null,
            'pendingPromise' => $this->account->promises()->pending()->with('currency')->orderBy('promised_on')->first(),
            'canChange' => auth()->user()->can('delete-records'),
        ];
    }

    public function render()
    {
        return $this->view()->title($this->account->name);
    }
}; ?>

<div class="pb-60">
    {{-- Header --}}
    <header class="sticky top-0 z-20 flex items-center gap-3 bg-white px-4 py-4 shadow-sm dark:bg-slate-900">
        <a href="{{ route('dashboard') }}" wire:navigate class="dd-icon-btn size-12 bg-slate-100 text-slate-500 dark:bg-slate-800" aria-label="رجوع"><flux:icon.arrow-right class="size-6" /></a>
        <a href="{{ $canChange ? route('tenant.accounts.edit', $account) : '#' }}" @if ($canChange) wire:navigate @endif class="flex size-14 shrink-0 items-center justify-center rounded-2xl text-2xl font-medium shadow-lg {{ Palette::avatar($account->id) }}">{{ $account->initial() }}</a>
        <a href="{{ $canChange ? route('tenant.accounts.edit', $account) : '#' }}" @if ($canChange) wire:navigate @endif class="min-w-0 flex-1">
            <h1 class="truncate text-2xl font-bold">{{ $account->name }}</h1>
            <p class="flex items-center gap-1.5 text-sm font-medium text-pink-600"><span class="size-1.5 rounded-full bg-pink-500"></span>{{ $count }} عمليات</p>
        </a>
        <button type="button" wire:click="$set('showShare', true)" class="dd-icon-btn size-14 bg-violet-50 text-violet-500 dark:bg-violet-500/10" aria-label="مشاركة كشف الحساب"><flux:icon.share variant="solid" class="size-6" /></button>
        <button type="button" wire:click="$toggle('showSearch')" class="dd-icon-btn size-14 bg-blue-50 text-blue-500 dark:bg-blue-500/10" aria-label="بحث"><flux:icon.magnifying-glass class="size-7" /></button>
        <button type="button" wire:click="$set('showFilter', true)" @class(['dd-icon-btn size-14', 'bg-slate-900 text-white' => $currencyFilter, 'bg-slate-100 text-slate-600 dark:bg-slate-800' => ! $currencyFilter]) aria-label="تصفية"><flux:icon.funnel variant="solid" class="size-6" /></button>
    </header>

    {{-- Rating, due date, promise + quick actions --}}
    <div class="no-scrollbar flex items-center gap-2 overflow-x-auto px-4 pt-3 text-sm">
        @if ($score)
            <span @class(['dd-chip shrink-0 px-3 py-1.5',
                'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $score['color'] === 'emerald',
                'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300' => $score['color'] === 'sky',
                'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300' => $score['color'] === 'amber',
                'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $score['color'] === 'rose',
            ]) title="{{ $score['reasons'] ? implode('، ', $score['reasons']) : 'سجل سداد جيد' }}">⭐ {{ $score['label'] }} · {{ $score['score'] }}/100</span>
        @endif
        @if ($account->due_date)
            <span @class(['dd-chip shrink-0 px-3 py-1.5', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10' => $account->due_date->lte(today()) && $baseBalance < 0, 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' => ! ($account->due_date->lte(today()) && $baseBalance < 0)])>
                <flux:icon.bell-alert variant="micro" /> موعد السداد {{ $account->due_date->format('Y/m/d') }}
            </span>
        @endif
        @if ($pendingPromise)
            <span class="dd-chip shrink-0 bg-indigo-50 px-3 py-1.5 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">🤝 وعد: {{ $pendingPromise->describe() }}</span>
        @endif
        <span class="flex-1"></span>
        <button type="button" wire:click="openPromise" class="dd-chip shrink-0 bg-white px-3 py-1.5 text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700">🤝 وعد سداد</button>
        <button type="button" wire:click="togglePin" class="dd-chip shrink-0 bg-white px-3 py-1.5 text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700">
            <flux:icon :name="$account->pinned_at ? 'bookmark-slash' : 'bookmark'" variant="micro" /> {{ $account->pinned_at ? 'إلغاء التثبيت' : 'تثبيت' }}
        </button>
        <a href="{{ route('tenant.accounts.print', $account) }}" target="_blank" class="dd-chip shrink-0 bg-white px-3 py-1.5 text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700"><flux:icon.printer variant="micro" /> طباعة / PDF</a>
    </div>

    @if ($showSearch)
        <div class="px-4 pt-3">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="بحث في الملاحظات أو المبالغ..." class="dd-input" autofocus>
        </div>
    @endif

    @if ($openDisputes)
        <button type="button" wire:click="$set('showDisputes', true)" class="mx-4 mt-3 flex w-[calc(100%-2rem)] items-center gap-3 rounded-2xl bg-amber-100 p-4 text-start text-sm font-medium text-amber-900 dark:bg-amber-500/15 dark:text-amber-200">
            <flux:icon.chat-bubble-left-ellipsis variant="solid" class="size-6 shrink-0" />
            <span class="flex-1">اعترض العميل على كشف الحساب ({{ $openDisputes }} {{ $openDisputes === 1 ? 'اعتراض مفتوح' : 'اعتراضات مفتوحة' }})</span>
            <flux:icon.chevron-left variant="mini" />
        </button>
    @endif

    @if ($limit['state'] !== 'none' && $limit['state'] !== 'ok')
        <div @class(['mx-4 mt-3 flex items-center gap-3 rounded-2xl p-4 text-sm font-medium', 'bg-rose-600 text-white' => $limit['state'] === 'exceeded', 'bg-amber-100 text-amber-800' => $limit['state'] === 'warning'])>
            <flux:icon.exclamation-triangle variant="solid" class="size-6 shrink-0" />
            {{ $limit['state'] === 'exceeded' ? 'تجاوز هذا الحساب سقف الدين' : 'اقترب هذا الحساب من سقف الدين' }}:
            {{ number_format($limit['owed'], 2) }} / {{ number_format($limit['limit'], 2) }} {{ $base->code }}
        </div>
    @endif

    @if ($selecting)
        <div class="sticky top-[88px] z-20 mx-4 mt-3 flex items-center gap-2 rounded-2xl bg-slate-900 p-3 text-white shadow-xl">
            <button type="button" wire:click="cancelSelecting" class="dd-icon-btn size-10 bg-white/10"><flux:icon.x-mark variant="mini" /></button>
            <span class="flex-1 font-bold">تم تحديد {{ count($selected) }}</span>
            <button type="button" wire:click="openMove" @disabled(! $selected) class="dd-chip bg-white/10 disabled:opacity-40"><flux:icon.arrows-right-left variant="micro" /> نقل</button>
            <button type="button" wire:click="deleteTransaction" wire:confirm="حذف المعاملات المحددة؟" @disabled(! $selected) class="dd-chip bg-rose-600 disabled:opacity-40"><flux:icon.trash variant="micro" /> حذف</button>
        </div>
    @endif

    {{-- Transactions --}}
    <div class="space-y-3 px-3 pt-3">
        @forelse ($transactions as $tx)
            @php($credit = $tx->isCredit())
            <button type="button" wire:key="tx-{{ $tx->id }}" wire:click="openOptions({{ $tx->id }})" @class([
                'block w-full rounded-3xl border-2 bg-white p-5 text-start transition active:scale-[0.99] dark:bg-slate-900',
                'border-emerald-100 dark:border-emerald-500/20' => $credit,
                'border-rose-100 dark:border-rose-500/20' => ! $credit,
                'ring-4 ring-slate-900/80 dark:ring-white/70' => in_array($tx->id, $selected),
            ])>
                <div class="flex items-start gap-4">
                    <span @class(['flex size-16 shrink-0 items-center justify-center rounded-2xl text-white shadow-lg', 'bg-linear-to-br from-emerald-500 to-green-600 shadow-emerald-500/30' => $credit, 'bg-linear-to-br from-red-500 to-rose-600 shadow-rose-500/30' => ! $credit])>
                        <flux:icon :name="$credit ? 'arrow-up' : 'arrow-down'" class="size-8" />
                    </span>
                    <div class="min-w-0 flex-1 pt-2">
                        <p class="text-2xl font-bold">{{ $credit ? 'له' : 'عليه' }}
                            @if ($tx->kind !== 'entry')
                                <span class="dd-badge mr-1 bg-slate-100 align-middle text-xs font-medium text-slate-500 dark:bg-slate-800">{{ ['exchange' => 'صرف', 'transfer' => 'تحويل', 'split' => 'تقسيم'][$tx->kind] ?? '' }}</span>
                            @endif
                            @if ($tx->confirmed_at)
                                <span class="dd-badge mr-1 bg-emerald-50 align-middle text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300" title="أكّدها الزبون {{ $tx->confirmed_at->format('Y/m/d') }}">✓ أكّدها الزبون</span>
                            @endif
                        </p>
                        <p class="mt-2 flex items-center gap-1 text-sm text-slate-500" dir="ltr">
                            <span>{{ $tx->occurred_at->format('A h:i - d/m/Y') }}</span>
                            <flux:icon.clock variant="micro" />
                        </p>
                    </div>
                    <div class="text-left">
                        <p @class(['text-3xl font-bold tabular-nums', 'text-emerald-600' => $credit, 'text-rose-600' => ! $credit])>
                            {{ $tx->currency->format($tx->amount) }} <span class="text-base">{{ $tx->currency->code }}</span>
                        </p>
                        <p class="mt-1 text-sm text-slate-400">الرصيد:
                            <span @class(['font-bold tabular-nums', 'text-emerald-600' => $tx->running_balance >= 0, 'text-rose-600' => $tx->running_balance < 0])>{{ $tx->currency->format(abs($tx->running_balance)) }} {{ $tx->currency->code }}</span>
                        </p>
                        <span @class(['dd-badge mt-2 border px-4', 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10' => $credit, 'border-rose-200 bg-rose-50 text-rose-600 dark:border-rose-500/20 dark:bg-rose-500/10' => ! $credit])>{{ $credit ? 'دائن' : 'مدين' }}</span>
                    </div>
                </div>
                @if ($tx->notes || $tx->attachment_path)
                    <div class="mt-4 flex items-center gap-3 border-t border-slate-100 pt-3 text-slate-500 dark:border-slate-800">
                        <span class="flex size-9 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800"><flux:icon :name="$tx->attachment_path ? 'camera' : 'document-text'" variant="micro" /></span>
                        <span class="flex-1 text-base">{{ $tx->notes }}</span>
                    </div>
                @endif
            </button>
        @empty
            <div class="flex flex-col items-center py-20 text-center text-slate-500">
                <span class="flex size-24 items-center justify-center rounded-full bg-slate-200/60 dark:bg-slate-800"><flux:icon.document-text variant="solid" class="size-10" /></span>
                <p class="mt-5 text-lg font-bold text-slate-700 dark:text-slate-200">{{ $search || $currencyFilter ? 'لا توجد نتائج' : 'لا توجد معاملات بعد' }}</p>
                <p class="mt-1">اضغط على زر + لإضافة أول عملية.</p>
            </div>
        @endforelse
    </div>

    {{-- Bottom totals bar --}}
    <div class="fixed inset-x-0 bottom-0 z-30 mx-auto max-w-3xl rounded-t-[2rem] bg-white px-5 pb-5 pt-4 shadow-[0_-8px_30px_-12px_rgb(15_23_42/0.25)] dark:bg-slate-900">
        @if ($usedCurrencies->count() > 1 && ! $currencyFilter)
            <div class="no-scrollbar mb-3 flex justify-center gap-2 overflow-x-auto">
                @foreach ($usedCurrencies as $cur)
                    <button type="button" wire:click="setViewCurrency({{ $cur->id }})" @class(['dd-chip px-3 py-1', 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' => $viewCurrency->id === $cur->id, 'bg-slate-100 text-slate-500 dark:bg-slate-800' => $viewCurrency->id !== $cur->id])>{{ $cur->code }}</button>
                @endforeach
            </div>
        @endif
        <div class="grid grid-cols-2 divide-x divide-x-reverse divide-slate-200 text-center dark:divide-slate-700">
            <div>
                <p class="flex items-center justify-center gap-1 text-sm font-medium text-rose-600"><span class="rounded bg-rose-100 p-0.5 dark:bg-rose-500/20"><flux:icon.arrow-down variant="micro" /></span> إجمالي عليه</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-rose-600">{{ $viewCurrency->format($totalDebit) }} {{ $viewCurrency->code }}</p>
            </div>
            <div>
                <p class="flex items-center justify-center gap-1 text-sm font-medium text-emerald-600"><span class="rounded bg-emerald-100 p-0.5 dark:bg-emerald-500/20"><flux:icon.arrow-up variant="micro" /></span> إجمالي له</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-emerald-600">{{ $viewCurrency->format($totalCredit) }} {{ $viewCurrency->code }}</p>
            </div>
        </div>
        <div class="mt-4 flex items-center gap-3">
            <button type="button" wire:click="openCreate" class="dd-icon-btn size-16 shrink-0 {{ Palette::tile('dark') }} shadow-xl" aria-label="إضافة عملية"><flux:icon.plus class="size-8" /></button>
            <div @class([
                'flex-1 rounded-3xl border-2 px-4 py-2 text-center',
                'border-emerald-200 bg-emerald-50 dark:border-emerald-500/20 dark:bg-emerald-500/10' => round($net, 4) > 0,
                'border-rose-200 bg-rose-50 dark:border-rose-500/20 dark:bg-rose-500/10' => round($net, 4) < 0,
                'border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800' => round($net, 4) == 0,
            ])>
                <p class="text-sm text-slate-500">صافي {{ $viewCurrency->code }}</p>
                <p class="flex items-center justify-center gap-2 text-xl font-bold tabular-nums">
                    {{ $viewCurrency->format(abs($net)) }} {{ $viewCurrency->code }}
                    @if (round($net, 4) != 0)
                        <span @class(['dd-badge py-0 text-xs', 'bg-emerald-100 text-emerald-700' => $net > 0, 'bg-rose-100 text-rose-600' => $net < 0])>{{ $net > 0 ? 'له' : 'عليه' }}</span>
                    @endif
                </p>
            </div>
            <button type="button" wire:click="openExchange" class="dd-icon-btn size-16 shrink-0 bg-linear-to-br from-amber-500 to-orange-600 text-white shadow-xl shadow-orange-500/30" aria-label="صرف عملات"><flux:icon.banknotes variant="solid" class="size-8" /></button>
        </div>
    </div>

    {{-- Add / edit entry --}}
    <x-dd.sheet model="showForm">
        <form wire:submit.prevent class="space-y-4" x-data="{ calc: false }">
            <div class="flex items-center gap-3 rounded-3xl border border-blue-100 bg-blue-50/60 p-4 dark:border-blue-500/20 dark:bg-blue-500/10">
                <span class="flex size-14 items-center justify-center rounded-2xl {{ Palette::tile('blue') }} shadow-lg"><flux:icon.document-currency-dollar variant="solid" class="size-7" /></span>
                <div class="flex-1">
                    <h2 class="text-xl font-bold">{{ $editingId ? 'تعديل عملية' : 'إضافة عملية' }}</h2>
                    <p class="text-sm text-slate-500">لـ: {{ $account->name }}</p>
                </div>
                <label class="flex items-center gap-2 rounded-2xl border border-blue-200 bg-white px-3 py-2 font-bold text-blue-600 dark:border-blue-500/30 dark:bg-slate-900">
                    <input type="date" wire:model="date" class="w-32 bg-transparent text-sm focus:outline-none" dir="ltr">
                </label>
            </div>
            @error('date') <p class="dd-error">{{ $message }}</p> @enderror

            <div class="dd-soft space-y-3 p-4">
                <div class="flex items-center justify-between">
                    <p class="flex items-center gap-2 font-bold"><span class="flex size-9 items-center justify-center rounded-xl {{ Palette::tile('violet') }}"><flux:icon.circle-stack variant="micro" /></span> المبلغ</p>
                    <button type="button" x-on:click="calc = !calc" :class="calc ? 'ring-4 ring-violet-200' : ''" class="dd-icon-btn {{ Palette::tile('violet') }}" title="الآلة الحاسبة"><flux:icon.calculator variant="solid" /></button>
                </div>
                <div class="relative">
                    <input type="text" inputmode="decimal" wire:model="amount" x-ref="amount" placeholder="0.00" autofocus
                           class="dd-input h-20 border-2 border-slate-900 pl-28 text-left text-3xl font-bold dark:border-slate-400" dir="ltr">
                    <span class="dd-badge absolute left-4 top-1/2 -translate-y-1/2 bg-violet-100 px-3 py-1.5 text-lg text-violet-700 dark:bg-violet-500/20 dark:text-violet-300">{{ $converter->find((int) $currency_id)?->code }}</span>
                </div>
                <div x-show="calc" x-cloak class="grid grid-cols-4 gap-2" dir="ltr">
                    @foreach (['7', '8', '9', '÷', '4', '5', '6', '×', '1', '2', '3', '-', '.', '0', '(', '+'] as $key)
                        <button type="button" x-on:click="$wire.set('amount', ($refs.amount.value || '') + '{{ $key }}', false); $refs.amount.value += '{{ $key }}'" class="rounded-xl bg-white py-3 text-xl font-bold shadow-sm dark:bg-slate-900">{{ $key }}</button>
                    @endforeach
                    <button type="button" x-on:click="$wire.set('amount', '', false); $refs.amount.value = ''" class="col-span-2 rounded-xl bg-rose-50 py-3 font-bold text-rose-600">مسح</button>
                    <button type="button" x-on:click="$wire.set('amount', ($refs.amount.value || '') + ')', false); $refs.amount.value += ')'" class="rounded-xl bg-white py-3 text-xl font-bold shadow-sm dark:bg-slate-900">)</button>
                    <button type="button" x-on:click="$refs.amount.value = $refs.amount.value.slice(0, -1); $wire.set('amount', $refs.amount.value, false)" class="rounded-xl bg-white py-3 text-xl font-bold shadow-sm dark:bg-slate-900">⌫</button>
                </div>
                <p class="text-xs text-slate-400">يمكنك كتابة عملية حسابية مثل 150*3+20 وسيتم احتسابها تلقائياً.</p>
                @error('amount') <p class="dd-error">{{ $message }}</p> @enderror
            </div>

            <div class="no-scrollbar flex gap-2 overflow-x-auto pb-1">
                @foreach ($this->currencies as $cur)
                    <button type="button" wire:click="$set('currency_id', {{ $cur->id }})" @class(['dd-chip min-w-24 justify-center border py-3 text-lg', 'border-transparent bg-linear-to-br from-amber-400 to-amber-500 text-white shadow-lg shadow-amber-500/30' => $currency_id === $cur->id, 'border-slate-100 bg-white text-slate-500 dark:border-slate-800 dark:bg-slate-900' => $currency_id !== $cur->id])>{{ $cur->code }}</button>
                @endforeach
            </div>

            <div class="dd-soft flex gap-3 p-3">
                <label class="dd-icon-btn size-auto w-20 shrink-0 cursor-pointer flex-col border border-slate-200 bg-white text-slate-700 dark:border-slate-700 dark:bg-slate-900" title="إرفاق صورة">
                    @if ($photo)
                        <img src="{{ $photo->temporaryUrl() }}" class="size-full rounded-2xl object-cover" alt="">
                    @else
                        <flux:icon.camera variant="solid" class="size-9" />
                    @endif
                    <input type="file" wire:model="photo" accept="image/*" capture="environment" class="sr-only">
                </label>
                <div class="relative flex-1">
                    <flux:icon.document-text class="pointer-events-none absolute right-4 top-4 size-6 text-slate-400" />
                    <textarea wire:model="notes" rows="2" placeholder="الملاحظات (اختياري)" class="dd-input h-full pr-12"></textarea>
                </div>
            </div>
            @error('photo') <p class="dd-error">{{ $message }}</p> @enderror

            @unless ($editingId)
                <div x-data="ddInvoiceReader()" class="rounded-2xl border border-dashed border-indigo-300 bg-indigo-50/50 p-3 dark:border-indigo-500/40 dark:bg-indigo-500/10">
                    <label class="flex cursor-pointer items-center gap-3" :class="busy && 'pointer-events-none opacity-60'">
                        <span class="flex size-10 items-center justify-center rounded-xl {{ Palette::tile('indigo') }}"><flux:icon.document-magnifying-glass variant="solid" class="size-5" /></span>
                        <span class="flex-1">
                            <span class="block font-bold text-indigo-700 dark:text-indigo-300">قراءة من صورة فاتورة</span>
                            <span class="block text-xs text-slate-500" x-text="status || 'صوّر الفاتورة وسأستخرج المبلغ والتاريخ تلقائياً — على جهازك دون رفعها لأي جهة.'"></span>
                        </span>
                        <input type="file" accept="image/*" capture="environment" class="sr-only" x-on:change="read($event)">
                    </label>
                </div>
            @endunless

            @if ($limitWarning)
                <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300">
                    <p class="flex items-center gap-2 font-bold"><flux:icon.exclamation-triangle variant="mini" /> هذه العملية ستتجاوز سقف الدين</p>
                    <p class="mt-1 text-sm">السقف: {{ number_format($limitWarning['limit'], 2) }} {{ $base->code }} — الدين بعد العملية: {{ number_format($limitWarning['after'], 2) }} {{ $base->code }}</p>
                    <button type="button" wire:click="save('{{ $limitWarning['type'] }}', true)" class="dd-btn mt-3 w-full bg-rose-600 py-2.5 text-white">متابعة رغم التجاوز</button>
                </div>
            @endif

            <div class="grid grid-cols-2 gap-4 pt-2">
                <button type="button" wire:click="save('debit')" wire:loading.attr="disabled" class="dd-btn flex-col gap-0 bg-linear-to-br from-red-400 to-red-600 py-4 text-2xl text-white shadow-lg shadow-red-500/30">
                    <span class="flex items-center gap-2">عليه <flux:icon.arrow-down variant="mini" class="rounded-lg bg-white/20 p-0.5" /></span>
                    <span class="text-xs font-medium opacity-80">مدين</span>
                </button>
                <button type="button" wire:click="save('credit')" wire:loading.attr="disabled" class="dd-btn flex-col gap-0 bg-linear-to-br from-emerald-400 to-emerald-600 py-4 text-2xl text-white shadow-lg shadow-emerald-500/30">
                    <span class="flex items-center gap-2">له <flux:icon.arrow-up variant="mini" class="rounded-lg bg-white/20 p-0.5" /></span>
                    <span class="text-xs font-medium opacity-80">دائن</span>
                </button>
            </div>
        </form>
    </x-dd.sheet>

    {{-- Transaction options --}}
    <x-dd.sheet model="showOptions" title="خيارات العملية">
        @if ($tx = $this->selectedTransaction)
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                <a href="{{ $whatsapp->link($tx) }}" target="_blank" rel="noopener" class="group flex items-center gap-4 py-4">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-violet-50 text-violet-500 dark:bg-violet-500/10"><flux:icon.share variant="solid" /></span>
                    <span class="flex-1"><span class="block text-lg font-bold">مشاركة إشعار عبر واتساب</span><span class="text-sm text-slate-500">{{ $account->phone ? 'إرسال إلى +'.$account->whatsappNumber() : 'لا يوجد رقم — اختر جهة الاتصال من واتساب' }}</span></span>
                    <flux:icon.chevron-left variant="mini" class="text-slate-400" />
                </a>
                <button type="button" x-data x-on:click="ddCopyToast(@js($whatsapp->forTransaction($tx)), 'تم نسخ نص الإشعار')" class="group flex w-full items-center gap-4 py-4 text-start">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-500 dark:bg-slate-800"><flux:icon.document-duplicate variant="solid" /></span>
                    <span class="flex-1"><span class="block text-lg font-bold">نسخ نص الإشعار</span><span class="text-sm text-slate-500">لإرساله عبر أي تطبيق آخر</span></span>
                </button>
                @if ($tx->attachment_path)
                    <a href="{{ route('tenant.attachments.show', $tx) }}" target="_blank" class="group flex items-center gap-4 py-4">
                        <span class="flex size-14 items-center justify-center rounded-2xl bg-amber-50 text-amber-500 dark:bg-amber-500/10"><flux:icon.photo variant="solid" /></span>
                        <span class="flex-1 text-lg font-bold">عرض الصورة المرفقة</span>
                    </a>
                @endif
                @if ($canChange)
                <button type="button" wire:click="openEdit({{ $tx->id }})" class="flex w-full items-center gap-4 py-4 text-start">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-blue-50 text-blue-500 dark:bg-blue-500/10"><flux:icon.pencil-square variant="solid" /></span>
                    <span class="flex-1"><span class="block text-lg font-bold">تعديل العملية</span><span class="text-sm text-slate-500">تعديل بيانات العملية</span></span>
                    <flux:icon.chevron-left variant="mini" class="text-slate-400" />
                </button>
                <button type="button" wire:click="openMove" class="flex w-full items-center gap-4 py-4 text-start">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-sky-50 text-sky-600 dark:bg-sky-500/10"><flux:icon.arrows-right-left variant="solid" /></span>
                    <span class="flex-1"><span class="block text-lg font-bold">نقل المعاملة</span><span class="text-sm text-slate-500">نقل هذه المعاملة إلى حساب شخص آخر</span></span>
                    <flux:icon.chevron-left variant="mini" class="text-slate-400" />
                </button>
                <button type="button" wire:click="startSelecting" class="flex w-full items-center gap-4 py-4 text-start">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10"><flux:icon.check-circle /></span>
                    <span class="flex-1"><span class="block text-lg font-bold">تحديد متعدد</span><span class="text-sm text-slate-500">تحديد عدة معاملات لنقلها أو حذفها دفعة واحدة</span></span>
                    <flux:icon.chevron-left variant="mini" class="text-slate-400" />
                </button>
                <button type="button" wire:click="deleteTransaction({{ $tx->id }})" wire:confirm="حذف هذه العملية؟ يمكنك استرجاعها من سلة المحذوفات." class="flex w-full items-center gap-4 py-4 text-start">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-rose-50 text-rose-500 dark:bg-rose-500/10"><flux:icon.trash variant="solid" /></span>
                    <span class="flex-1"><span class="block text-lg font-bold text-rose-600">حذف العملية</span><span class="text-sm text-slate-500">نقلها إلى سلة المحذوفات</span></span>
                </button>
                @endif
            </div>
        @endif
    </x-dd.sheet>

    {{-- Payment promise --}}
    <x-dd.sheet model="showPromise" title="تسجيل وعد بالسداد">
        <form wire:submit="savePromise" class="space-y-4">
            <p class="rounded-2xl bg-indigo-50 p-4 text-sm text-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-200">
                عندما يقول {{ $account->name }} «بدفع يوم كذا» سجّل الوعد هنا. إذا وصلت الدفعة قبل الموعد يُعلَّم «التزم»، وإذا فات اليوم دون دفع يُعلَّم «أخلف» ويؤثر على تقييمه.
            </p>
            <div>
                <label class="dd-label">سيدفع بتاريخ *</label>
                <input type="date" wire:model="promiseDate" min="{{ now()->format('Y-m-d') }}" class="dd-input" dir="ltr">
                @error('promiseDate') <p class="dd-error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="dd-label">المبلغ الموعود (اختياري)</label>
                    <input type="text" inputmode="decimal" wire:model="promiseAmount" class="dd-input text-left" dir="ltr" placeholder="أي دفعة">
                    @error('promiseAmount') <p class="dd-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="dd-label">العملة</label>
                    <select wire:model="promiseCurrencyId" class="dd-input">
                        @foreach ($this->currencies as $cur)
                            <option value="{{ $cur->id }}">{{ $cur->code }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <input type="text" wire:model="promiseNotes" class="dd-input" placeholder="ملاحظة (اختياري)">
            <button type="submit" class="dd-btn-primary w-full">حفظ الوعد</button>
        </form>
    </x-dd.sheet>

    {{-- Move to another account --}}
    <x-dd.sheet model="showMove" title="نقل إلى حساب آخر">
        <input type="search" wire:model.live.debounce.300ms="moveSearch" placeholder="ابحث عن الشخص..." class="dd-input mb-3">
        <div class="space-y-2">
            @forelse ($this->moveTargets as $target)
                <button type="button" wire:key="mv-{{ $target->id }}" wire:click="moveTo({{ $target->id }})" wire:confirm="نقل {{ count($selected) }} معاملة إلى {{ $target->name }}؟" class="flex w-full items-center gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-3 text-start dark:border-slate-800 dark:bg-slate-800/50">
                    <span class="flex size-12 items-center justify-center rounded-xl text-xl {{ Palette::avatar($target->id) }}">{{ $target->initial() }}</span>
                    <span class="flex-1 text-lg font-bold">{{ $target->name }}</span>
                    <flux:icon.chevron-left variant="mini" class="text-slate-400" />
                </button>
            @empty
                <p class="py-8 text-center text-slate-500">لا توجد حسابات أخرى.</p>
            @endforelse
        </div>
    </x-dd.sheet>

    {{-- Currency filter --}}
    <x-dd.sheet model="showFilter" title="تصفية حسب العملة">
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="filterCurrency" @class(['dd-chip', 'bg-slate-900 text-white' => ! $currencyFilter, 'bg-slate-100 dark:bg-slate-800' => $currencyFilter])>كل العملات</button>
            @foreach ($usedCurrencies as $cur)
                <button type="button" wire:click="filterCurrency({{ $cur->id }})" @class(['dd-chip', 'bg-slate-900 text-white' => $currencyFilter === $cur->id, 'bg-slate-100 dark:bg-slate-800' => $currencyFilter !== $cur->id])>{{ $cur->name }}</button>
            @endforeach
        </div>
    </x-dd.sheet>

    {{-- Currency exchange --}}
    <x-dd.sheet model="showExchange">
        <form wire:submit="saveExchange" class="space-y-5">
            <div class="flex items-center gap-3">
                <span class="flex size-14 items-center justify-center rounded-2xl bg-linear-to-br from-cyan-400 to-cyan-600 text-white shadow-lg"><flux:icon.banknotes variant="solid" class="size-7" /></span>
                <h2 class="flex-1 text-3xl font-bold">صرف عملات</h2>
                <input type="date" wire:model="exDate" class="rounded-2xl border border-cyan-200 bg-cyan-50 px-3 py-2 text-sm font-bold text-cyan-700 dark:border-cyan-500/30 dark:bg-cyan-500/10" dir="ltr">
            </div>

            <div class="grid grid-cols-2 gap-3">
                @foreach (['buy' => ['شراء', 'arrow-up', 'emerald'], 'sell' => ['بيع', 'arrow-down', 'rose']] as $dir => [$label, $icon, $color])
                    <button type="button" wire:click="$set('exDirection', '{{ $dir }}')" @class([
                        'flex items-center justify-center gap-3 rounded-3xl border-2 py-5 text-xl font-bold transition',
                        'border-emerald-300 bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10' => $exDirection === $dir && $dir === 'buy',
                        'border-rose-300 bg-rose-50 text-rose-600 dark:bg-rose-500/10' => $exDirection === $dir && $dir === 'sell',
                        'border-transparent bg-slate-100 text-slate-500 dark:bg-slate-800' => $exDirection !== $dir,
                    ])>
                        <span @class(['flex size-10 items-center justify-center rounded-xl', $exDirection === $dir ? Palette::tile($color) : 'bg-slate-200 dark:bg-slate-700'])><flux:icon :name="$icon" variant="mini" /></span>
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            <div class="dd-soft space-y-4 p-5">
                <p class="flex items-center gap-2 font-bold"><span class="flex size-9 items-center justify-center rounded-xl {{ Palette::tile('blue') }}"><flux:icon.circle-stack variant="micro" /></span> {{ $exDirection === 'buy' ? 'العملة المشتراة' : 'العملة المباعة' }}</p>
                <div>
                    <label class="dd-label">العملة *</label>
                    <select wire:model.live="exCurrencyId" class="dd-input">
                        @foreach ($this->currencies->where('is_base', false) as $cur)
                            <option value="{{ $cur->id }}">{{ $cur->name }} ({{ $cur->code }})</option>
                        @endforeach
                    </select>
                    @error('exCurrencyId') <p class="dd-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="dd-label">المبلغ *</label>
                    <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="exAmount" class="dd-input text-left text-xl font-bold" dir="ltr" placeholder="0">
                    @error('exAmount') <p class="dd-error">{{ $message }}</p> @enderror
                </div>

                <p class="flex items-center gap-2 border-t border-slate-200 pt-4 font-bold dark:border-slate-700"><span class="flex size-9 items-center justify-center rounded-xl {{ Palette::tile('violet') }}"><flux:icon.scale variant="micro" /></span> المبلغ المقابل بالعملة الأساسية</p>
                @php($exCur = $converter->find((int) $exCurrencyId))
                @php($counter = (MathExpression::evaluate($exAmount) ?? 0) * (float) $exRate)
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="dd-label">سعر الصرف *</label>
                        <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="exRate" class="dd-input text-left" dir="ltr">
                        <p class="mt-1 text-xs text-slate-400">1 {{ $exCur?->code }} = ? {{ $base->code }}</p>
                        @error('exRate') <p class="dd-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="dd-label">المبلغ المقابل</label>
                        <div class="dd-input bg-slate-100 text-left text-xl font-bold dark:bg-slate-800" dir="ltr">{{ $base->format($counter) }} <span class="text-sm">{{ $base->code }}</span></div>
                    </div>
                </div>
                <p class="rounded-xl bg-white p-3 text-sm text-slate-500 dark:bg-slate-900">
                    @if ($exDirection === 'buy')
                        سيُسجَّل للحساب <b class="text-emerald-600">له {{ $exAmount ?: 0 }} {{ $exCur?->code }}</b> و <b class="text-rose-600">عليه {{ $base->format($counter) }} {{ $base->code }}</b>.
                    @else
                        سيُسجَّل للحساب <b class="text-rose-600">عليه {{ $exAmount ?: 0 }} {{ $exCur?->code }}</b> و <b class="text-emerald-600">له {{ $base->format($counter) }} {{ $base->code }}</b>.
                    @endif
                </p>

                <div class="flex gap-3 border-t border-slate-200 pt-4 dark:border-slate-700">
                    <label class="dd-icon-btn size-auto w-20 shrink-0 cursor-pointer border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                        @if ($exPhoto)
                            <img src="{{ $exPhoto->temporaryUrl() }}" class="size-full rounded-2xl object-cover" alt="">
                        @else
                            <flux:icon.camera variant="solid" class="size-8" />
                        @endif
                        <input type="file" wire:model="exPhoto" accept="image/*" class="sr-only">
                    </label>
                    <textarea wire:model="exNotes" rows="2" placeholder="ملاحظات إضافية (اختياري)" class="dd-input"></textarea>
                </div>
            </div>

            <button type="submit" class="dd-btn w-full bg-linear-to-l from-cyan-400 to-cyan-600 py-4 text-lg text-white shadow-lg shadow-cyan-500/30" wire:loading.attr="disabled">
                <flux:icon.check-circle class="size-6" /> تنفيذ وحفظ العملية
            </button>
        </form>
    </x-dd.sheet>

    {{-- Share the statement (live link + text summary) --}}
    <x-dd.sheet model="showShare" title="مشاركة كشف الحساب">
        @if ($showShare)
            <div class="space-y-6">
                <section class="rounded-3xl border border-violet-100 bg-violet-50/60 p-5 dark:border-violet-500/20 dark:bg-violet-500/10">
                    <h3 class="flex items-center gap-2 text-lg font-bold"><flux:icon.link variant="solid" class="size-5 text-violet-500" /> رابط كشف الحساب الحي</h3>

                    @if (! $liveStatement)
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">خطتك الحالية لا تتضمن الرابط الحي. يمكنك مشاركة الكشف كنص بالأسفل.</p>
                        <a href="{{ route('tenant.upgrade') }}" wire:navigate class="dd-btn mt-4 w-full bg-linear-to-l from-amber-500 to-orange-500 text-white"><flux:icon.lock-closed variant="micro" /> عرض الخطط</a>
                    @elseif (! $account->hasStatementLink())
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">رابط مؤمَّن تُرسله للعميل، يفتح صفحة برصيده وحركاته محدَّثة لحظياً، ويستطيع منها الاعتراض على أي حركة مباشرة.</p>
                        <button type="button" wire:click="enableStatementLink" class="dd-btn-primary mt-4 w-full"><flux:icon.link variant="micro" /> تفعيل الرابط</button>
                    @else
                        <div class="mt-3 flex items-center gap-2" x-data>
                            <input type="text" readonly value="{{ $account->statementUrl() }}" dir="ltr" class="dd-input flex-1 bg-white text-left text-sm dark:bg-slate-900" x-on:focus="$el.select()">
                            <button type="button" x-on:click="ddCopyToast(@js($account->statementUrl()), 'تم نسخ الرابط')" class="dd-icon-btn size-12 shrink-0 bg-white text-violet-600 dark:bg-slate-900" aria-label="نسخ الرابط"><flux:icon.document-duplicate variant="solid" class="size-5" /></button>
                            <a href="{{ $account->statementUrl() }}" target="_blank" rel="noopener" class="dd-icon-btn size-12 shrink-0 bg-white text-slate-600 dark:bg-slate-900" aria-label="معاينة"><flux:icon.eye variant="solid" class="size-5" /></a>
                        </div>
                        <p class="mt-2 text-sm text-slate-500">
                            {{ $account->statement_viewed_at ? 'آخر فتح من العميل: '.$account->statement_viewed_at->diffForHumans() : 'لم يفتح العميل الرابط بعد.' }}
                        </p>
                        <div class="mt-4 grid grid-cols-2 gap-2">
                            <button type="button" wire:click="regenerateStatementLink" wire:confirm="تجديد الرابط؟ سيتوقف الرابط القديم عن العمل." class="dd-btn border border-slate-200 bg-white text-sm text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><flux:icon.arrow-path variant="micro" /> تجديد الرابط</button>
                            <button type="button" wire:click="disableStatementLink" wire:confirm="إيقاف الرابط؟ لن يتمكن العميل من فتحه." class="dd-btn border border-rose-200 bg-white text-sm text-rose-600 dark:border-rose-500/30 dark:bg-slate-900"><flux:icon.no-symbol variant="micro" /> إيقاف الرابط</button>
                        </div>
                    @endif
                </section>

                <section wire:key="share-text-{{ md5((string) $account->statement_token) }}"
                         x-data="{
                            withLink: {{ $liveStatement && $account->hasStatementLink() ? 'true' : 'false' }},
                            full: @js($statementText),
                            plain: @js($statementTextNoLink),
                            url: @js($liveStatement ? $account->statementUrl() : null),
                            phone: @js($account->whatsappNumber()),
                            get text() { return this.withLink && this.url ? this.full : this.plain },
                            enc(v) { return encodeURIComponent(v) },
                            copy(message) { window.ddCopyToast(this.text, message) },
                         }">
                    <h3 class="flex items-center gap-2 text-lg font-bold"><flux:icon.chat-bubble-bottom-center-text variant="solid" class="size-5 text-emerald-500" /> كشف حساب نصي ملخص</h3>

                    <template x-if="url">
                        <label class="mt-3 flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                            <input type="checkbox" x-model="withLink" class="size-4 rounded border-slate-300">
                            إرفاق رابط الكشف الحي في النص
                        </label>
                    </template>

                    <pre class="mt-3 max-h-56 overflow-y-auto whitespace-pre-wrap rounded-2xl bg-slate-50 p-4 font-sans text-sm leading-relaxed text-slate-700 dark:bg-slate-800 dark:text-slate-200" x-text="text"></pre>

                    <div class="mt-4 grid grid-cols-3 gap-2 text-center text-xs font-bold sm:grid-cols-4">
                        <a x-bind:href="'https://wa.me/' + (phone ?? '') + '?text=' + enc(text)" target="_blank" rel="noopener" class="flex flex-col items-center gap-2 rounded-2xl bg-emerald-50 p-3 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                            <flux:icon.chat-bubble-oval-left-ellipsis variant="solid" class="size-6" /> واتساب
                        </a>
                        <a x-bind:href="'sms:' + (phone ? '+' + phone : '') + '?&body=' + enc(text)" class="flex flex-col items-center gap-2 rounded-2xl bg-sky-50 p-3 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">
                            <flux:icon.device-phone-mobile variant="solid" class="size-6" /> رسالة SMS
                        </a>
                        <a x-bind:href="withLink && url ? 'https://t.me/share/url?url=' + enc(url) + '&text=' + enc(plain) : 'https://t.me/share/url?url=' + enc(text)" target="_blank" rel="noopener" class="flex flex-col items-center gap-2 rounded-2xl bg-blue-50 p-3 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">
                            <flux:icon.paper-airplane variant="solid" class="size-6" /> تلغرام
                        </a>
                        <template x-if="withLink && url">
                            <a x-bind:href="'https://www.facebook.com/sharer/sharer.php?u=' + enc(url)" target="_blank" rel="noopener" class="flex flex-col items-center gap-2 rounded-2xl bg-indigo-50 p-3 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                                <flux:icon.globe-alt variant="solid" class="size-6" /> فيسبوك
                            </a>
                        </template>
                        <button type="button" x-on:click="copy('تم نسخ النص — الصقه في محادثة إنستغرام'); window.open('https://www.instagram.com/direct/inbox/', '_blank', 'noopener')" class="flex flex-col items-center gap-2 rounded-2xl bg-pink-50 p-3 text-pink-700 dark:bg-pink-500/10 dark:text-pink-300">
                            <flux:icon.camera variant="solid" class="size-6" /> إنستغرام
                        </button>
                        <a x-bind:href="'mailto:?subject=' + enc(@js('كشف حساب '.$account->name)) + '&body=' + enc(text)" class="flex flex-col items-center gap-2 rounded-2xl bg-amber-50 p-3 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                            <flux:icon.envelope variant="solid" class="size-6" /> البريد
                        </a>
                        <button type="button" x-show="navigator.share" x-on:click="navigator.share(withLink && url ? { text: plain, url } : { text })" class="flex flex-col items-center gap-2 rounded-2xl bg-violet-50 p-3 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">
                            <flux:icon.share variant="solid" class="size-6" /> تطبيقات أخرى
                        </button>
                        <button type="button" x-on:click="copy('تم نسخ نص الكشف')" class="flex flex-col items-center gap-2 rounded-2xl bg-slate-100 p-3 text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                            <flux:icon.document-duplicate variant="solid" class="size-6" /> نسخ النص
                        </button>
                    </div>
                </section>
            </div>
        @endif
    </x-dd.sheet>

    {{-- Customer disputes raised from the live statement --}}
    <x-dd.sheet model="showDisputes" title="اعتراضات العميل">
        <div class="space-y-3">
            @forelse ($disputes as $dispute)
                <div wire:key="dispute-{{ $dispute->id }}" @class(['rounded-3xl border p-4', 'border-amber-200 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10' => $dispute->isOpen(), 'border-slate-200 dark:border-slate-700' => ! $dispute->isOpen()])>
                    <div class="flex items-center justify-between gap-2 text-sm">
                        <span class="font-bold">{{ $dispute->name ?: $account->name }}</span>
                        <span class="text-slate-500">{{ $dispute->created_at->format('Y/m/d h:i A') }}</span>
                    </div>
                    <p class="mt-1 text-xs text-slate-500">
                        @if ($dispute->transaction)
                            على حركة {{ $dispute->transaction->occurred_at->format('Y/m/d') }}: {{ $dispute->transaction->currency->format($dispute->transaction->amount) }} {{ $dispute->transaction->currency->code }} {{ $dispute->transaction->isCredit() ? 'له' : 'عليه' }}{{ $dispute->transaction->trashed() ? ' (محذوفة)' : '' }}
                        @else
                            على الكشف بشكل عام
                        @endif
                    </p>
                    <p class="mt-3 whitespace-pre-line text-base">{{ $dispute->message }}</p>
                    <div class="mt-3 flex items-center justify-between">
                        <span @class(['dd-badge text-xs', 'bg-amber-200 text-amber-900' => $dispute->isOpen(), 'bg-emerald-100 text-emerald-700' => ! $dispute->isOpen()])>{{ $dispute->isOpen() ? 'مفتوح' : 'تمت المعالجة' }}</span>
                        @if ($dispute->isOpen())
                            <button type="button" wire:click="resolveDispute({{ $dispute->id }})" class="dd-chip bg-emerald-600 text-white"><flux:icon.check variant="micro" /> تمت المعالجة</button>
                        @else
                            <button type="button" wire:click="reopenDispute({{ $dispute->id }})" class="dd-chip bg-slate-100 text-slate-600 dark:bg-slate-800">إعادة فتح</button>
                        @endif
                    </div>
                </div>
            @empty
                <p class="py-10 text-center text-slate-500">لا توجد اعتراضات.</p>
            @endforelse
        </div>
    </x-dd.sheet>
</div>
