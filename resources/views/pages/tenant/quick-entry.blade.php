<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use App\Services\LedgerService;
use App\Support\MathExpression;
use App\Support\Palette;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('الإدخال السريع')] class extends Component {
    public string $search = '';
    public ?int $accountId = null;
    public string $amount = '';
    public ?int $currency_id = null;
    public string $notes = '';

    public function mount(CurrencyConverter $converter): void
    {
        $this->currency_id = $converter->base()?->id;
    }

    #[Computed]
    public function results()
    {
        return Account::with('category')
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('phone', 'like', '%'.$this->search.'%')))
            ->orderByRaw('last_activity_at IS NULL, last_activity_at DESC')
            ->take(12)->get();
    }

    #[Computed]
    public function account(): ?Account
    {
        return $this->accountId ? Account::find($this->accountId) : null;
    }

    #[Computed]
    public function currencies()
    {
        $converter = app(CurrencyConverter::class);

        return $converter->usable();
    }

    public function choose(int $id): void
    {
        $this->accountId = $id;
        $this->search = '';
    }

    public function save(string $type, LedgerService $ledger, CurrencyConverter $converter): void
    {
        $value = MathExpression::evaluate($this->amount);

        $this->validate([
            'accountId' => ['required'],
            'amount' => ['required', fn ($a, $v, $fail) => ($value === null || $value <= 0) ? $fail('أدخل مبلغاً صحيحاً.') : null],
            'currency_id' => ['required', 'in:'.$this->currencies->pluck('id')->implode(',')],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['accountId.required' => 'اختر الشخص أولاً.'], ['amount' => 'المبلغ']);

        abort_unless(in_array($type, [Transaction::CREDIT, Transaction::DEBIT], true), 422);

        if (! auth()->user()->tenant->withinLimit('transactions')) {
            $this->dispatch('toast', message: 'وصلت للحد الأقصى من المعاملات في خطتك.', type: 'error');

            return;
        }

        $currency = $converter->find($this->currency_id);
        $ledger->record($this->account, $type, $value, $currency, $this->notes ?: null);

        $this->dispatch('toast', message: $this->account->name.': '.($type === 'credit' ? 'له ' : 'عليه ').$currency->format($value).' '.$currency->code);
        $this->reset('accountId', 'amount', 'notes');
    }

    public function with(LedgerService $ledger, CurrencyConverter $converter): array
    {
        return [
            'balances' => $ledger->balances($this->results->pluck('id')->all()),
            'converter' => $converter,
        ];
    }
}; ?>

<div class="pb-16">
    <x-dd.topbar title="الإدخال السريع" :back="route('dashboard')" />

    <div class="space-y-6 px-5">
        <section class="dd-card space-y-5 p-5">
            @if ($this->account)
                <div class="flex items-center gap-4 rounded-2xl border-2 border-slate-900 p-3 dark:border-slate-500">
                    <span class="flex size-14 items-center justify-center rounded-2xl text-2xl {{ Palette::avatar($this->account->id) }}">{{ $this->account->initial() }}</span>
                    <span class="flex-1 text-xl font-bold">{{ $this->account->name }}</span>
                    <button type="button" wire:click="$set('accountId', null)" class="dd-icon-btn size-10 bg-slate-100 dark:bg-slate-800" aria-label="تغيير"><flux:icon.x-mark variant="mini" /></button>
                </div>

                <div>
                    <label class="dd-label">المبلغ</label>
                    <input type="text" inputmode="decimal" wire:model="amount" autofocus class="dd-input h-16 text-left text-3xl font-bold" dir="ltr" placeholder="0.00">
                    @error('amount') <p class="dd-error">{{ $message }}</p> @enderror
                </div>
                <div class="no-scrollbar flex gap-2 overflow-x-auto">
                    @foreach ($this->currencies as $cur)
                        <button type="button" wire:click="$set('currency_id', {{ $cur->id }})" @class(['dd-chip border', 'border-transparent bg-amber-500 text-white' => $currency_id === $cur->id, 'border-slate-200 text-slate-500 dark:border-slate-700' => $currency_id !== $cur->id])>{{ $cur->code }}</button>
                    @endforeach
                </div>
                <input type="text" wire:model="notes" class="dd-input" placeholder="ملاحظات (اختياري)">
            @else
                <div class="relative">
                    <label class="absolute -top-3 right-5 bg-white px-2 text-sm text-slate-600 dark:bg-slate-900">ابحث عن شخص...</label>
                    <input type="search" wire:model.live.debounce.250ms="search" autofocus class="dd-input h-16 border-2 border-slate-900 pl-14 dark:border-slate-500">
                    <flux:icon.magnifying-glass class="pointer-events-none absolute left-5 top-1/2 size-7 -translate-y-1/2 text-slate-500" />
                </div>
                @error('accountId') <p class="dd-error">{{ $message }}</p> @enderror
                <div class="max-h-[26rem] divide-y divide-slate-100 overflow-y-auto rounded-3xl bg-white shadow-xl ring-1 ring-slate-100 dark:divide-slate-800 dark:bg-slate-900 dark:ring-slate-800">
                    @forelse ($this->results as $acc)
                        @php($bal = $converter->sumToBase($balances[$acc->id] ?? []))
                        <button type="button" wire:key="qe-{{ $acc->id }}" wire:click="choose({{ $acc->id }})" class="flex w-full items-center gap-4 p-4 text-start hover:bg-slate-50 dark:hover:bg-slate-800">
                            <span class="flex size-14 items-center justify-center rounded-2xl text-2xl {{ Palette::avatar($acc->id) }}">{{ $acc->initial() }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-lg font-bold">{{ $acc->name }}</span>
                                <span class="flex items-center gap-1 text-sm text-slate-400"><flux:icon.folder variant="micro" /> {{ $acc->category?->name }}</span>
                            </span>
                            @if (round($bal, 2) != 0)
                                <span @class(['dd-badge', 'bg-rose-50 text-rose-600' => $bal < 0, 'bg-emerald-50 text-emerald-600' => $bal > 0])>
                                    {{ number_format(abs($bal), 2) }} {{ $converter->base()->code }} {{ $bal < 0 ? 'عليه' : 'له' }}
                                </span>
                            @endif
                        </button>
                    @empty
                        <p class="p-6 text-center text-slate-500">لا توجد نتائج.</p>
                    @endforelse
                </div>
            @endif
        </section>

        <div class="grid grid-cols-2 gap-4">
            <button type="button" wire:click="save('debit')" @disabled(! $accountId) class="dd-btn bg-linear-to-br from-red-400 to-red-600 py-5 text-xl text-white shadow-lg shadow-red-500/30">
                <flux:icon.arrow-down variant="mini" class="rounded-full bg-white/25 p-0.5" /> عليه
            </button>
            <button type="button" wire:click="save('credit')" @disabled(! $accountId) class="dd-btn bg-linear-to-br from-emerald-400 to-emerald-600 py-5 text-xl text-white shadow-lg shadow-emerald-500/30">
                <flux:icon.arrow-up variant="mini" class="rounded-full bg-white/25 p-0.5" /> له
            </button>
        </div>
    </div>
</div>
