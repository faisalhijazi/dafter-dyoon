<?php

use App\Models\Account;
use App\Models\Category;
use App\Services\CurrencyConverter;
use App\Services\DebtLimitService;
use App\Services\LedgerService;
use App\Support\Palette;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('إدارة السقوف')] class extends Component {
    // General rule sheet
    public bool $showGeneral = false;
    public bool $generalOpen = true;
    public string $generalLimit = '';

    // Exception picker + editor
    public bool $showPicker = false;
    public string $pickerSearch = '';
    public string $pickerTab = 'all';
    public bool $showException = false;
    public ?int $exceptionId = null;
    public bool $exceptionOpen = false;
    public string $exceptionLimit = '';

    public function mount(): void
    {
        $tenant = auth()->user()->tenant;
        $this->generalOpen = $tenant->default_debt_limit === null;
        $this->generalLimit = $tenant->default_debt_limit !== null ? (string) (float) $tenant->default_debt_limit : '';
    }

    #[Computed]
    public function exceptions()
    {
        return Account::with('category')->where('has_custom_limit', true)->orderBy('name')->get();
    }

    #[Computed]
    public function pickerAccounts()
    {
        return Account::with('category')
            ->where('has_custom_limit', false)
            ->when($this->pickerTab !== 'all', fn ($q) => $q->where('category_id', (int) $this->pickerTab))
            ->when($this->pickerSearch !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$this->pickerSearch.'%')
                ->orWhere('phone', 'like', '%'.$this->pickerSearch.'%')))
            ->orderBy('name')->take(50)->get();
    }

    #[Computed]
    public function categories()
    {
        return Category::withCount(['accounts' => fn ($q) => $q->where('has_custom_limit', false)])->orderBy('sort_order')->get();
    }

    public function saveGeneral(): void
    {
        $this->validate(['generalLimit' => [$this->generalOpen ? 'nullable' : 'required', 'nullable', 'numeric', 'min:0']], attributes: ['generalLimit' => 'السقف']);

        auth()->user()->tenant->update(['default_debt_limit' => $this->generalOpen ? null : $this->generalLimit]);
        $this->showGeneral = false;
        $this->dispatch('toast', message: 'تم حفظ القاعدة العامة.');
    }

    public function pick(int $id): void
    {
        $this->exceptionId = $id;
        $this->exceptionOpen = false;
        $this->exceptionLimit = '';
        $this->showPicker = false;
        $this->showException = true;
    }

    public function editException(int $id): void
    {
        $account = Account::findOrFail($id);
        $this->exceptionId = $id;
        $this->exceptionOpen = $account->debt_limit === null;
        $this->exceptionLimit = $account->debt_limit !== null ? (string) (float) $account->debt_limit : '';
        $this->showException = true;
    }

    public function saveException(): void
    {
        $this->validate(['exceptionLimit' => [$this->exceptionOpen ? 'nullable' : 'required', 'nullable', 'numeric', 'min:0']], attributes: ['exceptionLimit' => 'السقف']);

        Account::findOrFail($this->exceptionId)->update([
            'has_custom_limit' => true,
            'debt_limit' => $this->exceptionOpen ? null : $this->exceptionLimit,
        ]);

        $this->showException = false;
        unset($this->exceptions, $this->pickerAccounts, $this->categories);
        $this->dispatch('toast', message: 'تم حفظ الاستثناء.');
    }

    public function removeException(): void
    {
        Account::findOrFail($this->exceptionId)->update(['has_custom_limit' => false, 'debt_limit' => null]);
        $this->showException = false;
        unset($this->exceptions, $this->pickerAccounts, $this->categories);
        $this->dispatch('toast', message: 'أصبح الحساب يتبع القاعدة العامة.');
    }

    public function with(LedgerService $ledger, CurrencyConverter $converter, DebtLimitService $limits): array
    {
        $balances = $ledger->balances($this->exceptions->pluck('id')->all());

        return [
            'tenant' => auth()->user()->tenant,
            'base' => $converter->base(),
            'statuses' => $this->exceptions->mapWithKeys(fn ($a) => [
                $a->id => $limits->status($a, auth()->user()->tenant, $converter->sumToBase($balances[$a->id] ?? [])),
            ]),
            'exceptionAccount' => $this->exceptionId ? Account::find($this->exceptionId) : null,
        ];
    }
}; ?>

<div class="pb-36">
    <x-dd.hero title="إدارة السقوف" subtitle="ضبط قيود الأرصدة والتنبيهات" :back="route('tenant.settings')" icon="shield-check" />

    <div class="space-y-8 px-5 pt-8">
        @unless ($tenant->canUse('debt_limits'))
            <a href="{{ route('tenant.upgrade') }}" wire:navigate class="flex items-center gap-4 rounded-3xl bg-linear-to-l from-amber-500 to-orange-500 p-5 text-white shadow-lg">
                <flux:icon.lock-closed variant="solid" class="size-8" />
                <span class="flex-1"><b class="block text-lg">ميزة الخطة الاحترافية</b>السقوف مفعّلة للحفظ، ولن تظهر التنبيهات حتى تقوم بالترقية.</span>
                <flux:icon.chevron-left variant="mini" />
            </a>
        @endunless

        <section>
            <h2 class="mb-4 flex items-center gap-3 text-lg font-bold">
                <span class="flex size-11 items-center justify-center rounded-xl {{ Palette::tile('blue') }}"><flux:icon.globe-alt variant="solid" class="size-6" /></span>
                القاعدة العامة
                <span class="h-px flex-1 bg-linear-to-l from-slate-200 to-transparent dark:from-slate-700"></span>
            </h2>
            @php($open = $tenant->default_debt_limit === null)
            <button type="button" wire:click="$set('showGeneral', true)" @class(['flex w-full items-center gap-5 rounded-[2rem] border-2 bg-white p-5 text-start shadow-sm dark:bg-slate-900', 'border-emerald-100 dark:border-emerald-500/20' => $open, 'border-rose-100 dark:border-rose-500/20' => ! $open])>
                <span @class(['flex size-20 shrink-0 items-center justify-center rounded-3xl shadow-xl', Palette::tile($open ? 'emerald' : 'rose')])>
                    <flux:icon :name="$open ? 'lock-open' : 'lock-closed'" variant="solid" class="size-9" />
                </span>
                <span class="flex-1">
                    <span class="block text-2xl font-bold">{{ $open ? 'حساب مفتوح' : 'سقف موحّد' }}</span>
                    <span class="mt-1 block text-slate-500">{{ $open ? 'لا توجد قيود على الأرصدة.' : 'الحد الأقصى للدين: '.number_format((float) $tenant->default_debt_limit, 2).' '.$base->code }}</span>
                </span>
                <span class="flex size-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-600 dark:bg-slate-800"><flux:icon.chevron-left variant="mini" /></span>
            </button>
        </section>

        <section>
            <h2 class="mb-4 flex items-center gap-3 text-lg font-bold">
                <span class="flex size-11 items-center justify-center rounded-xl {{ Palette::tile('emerald') }}"><flux:icon.user-plus variant="solid" class="size-6" /></span>
                الحسابات المستثناة
                <span class="h-px flex-1 bg-linear-to-l from-slate-200 to-transparent dark:from-slate-700"></span>
            </h2>

            <div class="space-y-3">
                @forelse ($this->exceptions as $account)
                    @php($st = $statuses[$account->id])
                    <button type="button" wire:key="ex-{{ $account->id }}" wire:click="editException({{ $account->id }})" class="dd-card block w-full p-5 text-start">
                        <div class="flex items-center gap-4">
                            <span class="flex size-14 items-center justify-center rounded-2xl text-2xl {{ Palette::avatar($account->id) }}">{{ $account->initial() }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-lg font-bold">{{ $account->name }}</span>
                                <span class="text-sm text-slate-500">{{ $account->debt_limit === null ? 'حساب مفتوح (بلا سقف)' : 'السقف: '.number_format((float) $account->debt_limit, 2).' '.$base->code }}</span>
                            </span>
                            @if ($st['state'] === 'exceeded')
                                <span class="dd-badge bg-rose-600 text-white">تجاوز</span>
                            @elseif ($st['state'] === 'warning')
                                <span class="dd-badge bg-amber-100 text-amber-700">قريب</span>
                            @elseif ($st['state'] === 'ok')
                                <span class="dd-badge bg-emerald-50 text-emerald-600">ضمن الحد</span>
                            @endif
                        </div>
                        @if ($st['limit'])
                            <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                <div @class(['h-full rounded-full', 'bg-rose-500' => $st['state'] === 'exceeded', 'bg-amber-400' => $st['state'] === 'warning', 'bg-emerald-500' => $st['state'] === 'ok']) style="width: {{ min(100, round($st['ratio'] * 100)) }}%"></div>
                            </div>
                            <p class="mt-1.5 text-xs text-slate-500">الدين الحالي: {{ number_format($st['owed'], 2) }} {{ $base->code }} ({{ min(999, round($st['ratio'] * 100)) }}%)</p>
                        @endif
                    </button>
                @empty
                    <div class="flex flex-col items-center py-10 text-center">
                        <span class="flex size-40 items-center justify-center rounded-full bg-emerald-50 text-slate-400 dark:bg-emerald-500/10"><flux:icon.user-plus variant="solid" class="size-16" /></span>
                        <p class="mt-6 text-2xl font-bold">لا توجد استثناءات</p>
                        <p class="mt-2 text-slate-500">كل الحسابات تتبع القاعدة العامة</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    <button type="button" wire:click="$set('showPicker', true)" class="dd-fab"><flux:icon.user-plus variant="solid" class="size-6" /> إضافة استثناء</button>

    {{-- General rule --}}
    <x-dd.sheet model="showGeneral" title="القاعدة العامة">
        <form wire:submit="saveGeneral" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <button type="button" wire:click="$set('generalOpen', true)" @class(['dd-btn border-2', 'border-emerald-400 bg-emerald-50 text-emerald-700' => $generalOpen, 'border-transparent bg-slate-100 text-slate-500 dark:bg-slate-800' => ! $generalOpen])><flux:icon.lock-open variant="mini" /> حساب مفتوح</button>
                <button type="button" wire:click="$set('generalOpen', false)" @class(['dd-btn border-2', 'border-rose-400 bg-rose-50 text-rose-700' => ! $generalOpen, 'border-transparent bg-slate-100 text-slate-500 dark:bg-slate-800' => $generalOpen])><flux:icon.lock-closed variant="mini" /> سقف محدد</button>
            </div>
            @unless ($generalOpen)
                <div>
                    <label class="dd-label">الحد الأقصى لدين كل حساب ({{ $base->code }})</label>
                    <input type="text" inputmode="decimal" wire:model="generalLimit" class="dd-input text-left text-xl font-bold" dir="ltr" placeholder="5000">
                    @error('generalLimit') <p class="dd-error">{{ $message }}</p> @enderror
                    <p class="mt-2 text-sm text-slate-500">يظهر تنبيه عند وصول الدين إلى 80% من السقف، ويُطلب تأكيد عند تجاوزه.</p>
                </div>
            @endunless
            <button type="submit" class="dd-btn-primary w-full">حفظ</button>
        </form>
    </x-dd.sheet>

    {{-- Account picker --}}
    <x-dd.sheet model="showPicker">
        <div class="flex items-center gap-4 border-b border-slate-900 pb-4 dark:border-slate-600">
            <span class="flex size-14 items-center justify-center rounded-2xl {{ Palette::tile('dark') }}"><flux:icon.user-group variant="solid" class="size-7" /></span>
            <div class="flex-1"><h2 class="text-xl font-bold">إضافة استثناء لحساب</h2><p class="text-sm text-slate-500">{{ $this->pickerAccounts->count() }} حساب</p></div>
            <button type="button" wire:click="$set('showPicker', false)" class="dd-icon-btn size-14 rounded-full bg-slate-100 text-slate-500 dark:bg-slate-800"><flux:icon.x-mark /></button>
        </div>
        <div class="relative mt-5">
            <flux:icon.magnifying-glass class="pointer-events-none absolute right-4 top-1/2 size-6 -translate-y-1/2 text-slate-600" />
            <input type="search" wire:model.live.debounce.300ms="pickerSearch" placeholder="بحث (الاسم، الهاتف)..." class="dd-input bg-slate-50 pr-14 dark:bg-slate-800">
        </div>
        <div class="no-scrollbar mt-4 flex gap-3 overflow-x-auto">
            @foreach ([['id' => 'all', 'name' => 'الكل', 'count' => $this->categories->sum('accounts_count')], ...$this->categories->map(fn ($c) => ['id' => (string) $c->id, 'name' => $c->name, 'count' => $c->accounts_count])] as $t)
                <button type="button" wire:click="$set('pickerTab', '{{ $t['id'] }}')" @class(['dd-chip gap-3 px-5 py-3 text-base', 'bg-linear-to-l from-slate-900 to-slate-700 text-white' => $pickerTab === $t['id'], 'bg-slate-100 text-slate-500 dark:bg-slate-800' => $pickerTab !== $t['id']])>
                    {{ $t['name'] }} <span @class(['rounded-full px-2 text-xs', 'bg-white/20' => $pickerTab === $t['id'], 'bg-slate-200 dark:bg-slate-700' => $pickerTab !== $t['id']])>{{ $t['count'] }}</span>
                </button>
            @endforeach
        </div>
        <div class="mt-4 space-y-3">
            @foreach ($this->pickerAccounts as $acc)
                <button type="button" wire:key="pk-{{ $acc->id }}" wire:click="pick({{ $acc->id }})" class="flex w-full items-center gap-4 rounded-3xl border border-slate-200 bg-slate-50 p-4 text-start dark:border-slate-800 dark:bg-slate-800/50">
                    <span class="flex size-16 items-center justify-center rounded-2xl text-2xl {{ Palette::avatar($acc->id) }}">{{ $acc->initial() }}</span>
                    <span class="flex-1">
                        <span class="block text-xl font-bold">{{ $acc->name }}</span>
                        <span class="dd-badge mt-1 bg-slate-200/70 text-slate-600 dark:bg-slate-700 dark:text-slate-300"><flux:icon.folder variant="micro" /> {{ $acc->category?->name }}</span>
                    </span>
                    <flux:icon.chevron-left variant="mini" class="text-slate-400" />
                </button>
            @endforeach
        </div>
    </x-dd.sheet>

    {{-- Exception editor --}}
    <x-dd.sheet model="showException" :title="'سقف '.($exceptionAccount?->name ?? '')">
        <form wire:submit="saveException" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <button type="button" wire:click="$set('exceptionOpen', true)" @class(['dd-btn border-2', 'border-emerald-400 bg-emerald-50 text-emerald-700' => $exceptionOpen, 'border-transparent bg-slate-100 text-slate-500 dark:bg-slate-800' => ! $exceptionOpen])>مفتوح (بلا سقف)</button>
                <button type="button" wire:click="$set('exceptionOpen', false)" @class(['dd-btn border-2', 'border-rose-400 bg-rose-50 text-rose-700' => ! $exceptionOpen, 'border-transparent bg-slate-100 text-slate-500 dark:bg-slate-800' => $exceptionOpen])>سقف خاص</button>
            </div>
            @unless ($exceptionOpen)
                <div>
                    <label class="dd-label">الحد الأقصى للدين ({{ $base->code }})</label>
                    <input type="text" inputmode="decimal" wire:model="exceptionLimit" class="dd-input text-left text-xl font-bold" dir="ltr">
                    @error('exceptionLimit') <p class="dd-error">{{ $message }}</p> @enderror
                </div>
            @endunless
            <button type="submit" class="dd-btn-primary w-full">حفظ الاستثناء</button>
            @if ($exceptionAccount?->has_custom_limit)
                <button type="button" wire:click="removeException" class="dd-btn w-full border border-rose-200 text-rose-600">إزالة الاستثناء</button>
            @endif
        </form>
    </x-dd.sheet>
</div>
