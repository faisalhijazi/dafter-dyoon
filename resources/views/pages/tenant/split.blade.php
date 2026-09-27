<?php

use App\Models\Account;
use App\Services\CurrencyConverter;
use App\Services\LedgerService;
use App\Support\MathExpression;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('تقسيم فاتورة')] class extends Component {
    public string $total = '';
    public ?int $currency_id = null;
    public string $type = 'debit';
    public string $notes = '';
    public string $mode = 'equal'; // equal | custom

    /** @var array<int, string> account_id => custom share */
    public array $shares = [];

    public string $search = '';

    public function mount(CurrencyConverter $converter): void
    {
        $this->currency_id = $converter->base()?->id;
    }

    #[Computed]
    public function accounts()
    {
        return Account::when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->orderBy('name')->take(50)->get(['id', 'name']);
    }

    #[Computed]
    public function currencies()
    {
        $converter = app(CurrencyConverter::class);

        return $converter->usable();
    }

    public function toggle(int $id): void
    {
        if (array_key_exists($id, $this->shares)) {
            unset($this->shares[$id]);
        } else {
            $this->shares[$id] = '';
        }
    }

    /** @return array<int, float> */
    private function computedShares(): array
    {
        $ids = array_keys($this->shares);

        if ($this->mode === 'custom') {
            return collect($this->shares)->map(fn ($v) => (float) (MathExpression::evaluate($v) ?? 0))->all();
        }

        $total = MathExpression::evaluate($this->total) ?? 0;
        $count = count($ids);

        if (! $count) {
            return [];
        }

        // Equal split in cents; the remainder goes to the first person so the sum is exact.
        $each = floor($total * 100 / $count) / 100;
        $result = array_fill_keys($ids, $each);
        $result[$ids[0]] = round($total - $each * ($count - 1), 2);

        return $result;
    }

    public function save(LedgerService $ledger, CurrencyConverter $converter)
    {
        $shares = $this->computedShares();

        $this->validate([
            'type' => ['required', 'in:credit,debit'],
            'currency_id' => ['required', 'in:'.$this->currencies->pluck('id')->implode(',')],
            'total' => [$this->mode === 'equal' ? 'required' : 'nullable'],
            'shares' => ['array', 'min:2'],
        ], ['shares.min' => 'اختر شخصين على الأقل.'], ['total' => 'المبلغ الإجمالي']);

        if (array_sum($shares) <= 0) {
            $this->addError('total', 'أدخل مبالغ صحيحة.');

            return;
        }

        $currency = $converter->find($this->currency_id);
        $count = $ledger->split($shares, $this->type, $currency, $this->notes ?: 'تقسيم فاتورة')->count();

        session()->flash('success', "تم تقسيم الفاتورة على {$count} أشخاص.");

        return $this->redirectRoute('dashboard', navigate: true);
    }

    public function with(): array
    {
        return ['preview' => $this->computedShares()];
    }
}; ?>

<div class="pb-16">
    <x-dd.topbar title="تقسيم فاتورة / حساب" :back="route('dashboard')" />

    <form wire:submit="save" class="space-y-5 px-5">
        <div class="dd-card space-y-5 p-6">
            <div class="grid grid-cols-2 gap-2 rounded-2xl bg-slate-100 p-1.5 dark:bg-slate-800">
                @foreach (['equal' => 'بالتساوي', 'custom' => 'مبالغ مخصصة'] as $key => $label)
                    <button type="button" wire:click="$set('mode', '{{ $key }}')" @class(['rounded-xl py-2.5 font-bold', 'bg-white shadow dark:bg-slate-900' => $mode === $key, 'text-slate-500' => $mode !== $key])>{{ $label }}</button>
                @endforeach
            </div>

            @if ($mode === 'equal')
                <div class="grid grid-cols-3 gap-3">
                    <div class="col-span-2">
                        <label class="dd-label">المبلغ الإجمالي *</label>
                        <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="total" class="dd-input text-left text-xl font-bold" dir="ltr">
                        @error('total') <p class="dd-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="dd-label">العملة</label>
                        <select wire:model="currency_id" class="dd-input">
                            @foreach ($this->currencies as $cur) <option value="{{ $cur->id }}">{{ $cur->code }}</option> @endforeach
                        </select>
                    </div>
                </div>
            @else
                <div>
                    <label class="dd-label">العملة</label>
                    <select wire:model="currency_id" class="dd-input">
                        @foreach ($this->currencies as $cur) <option value="{{ $cur->id }}">{{ $cur->name }}</option> @endforeach
                    </select>
                    @error('total') <p class="dd-error">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="grid grid-cols-2 gap-3">
                <button type="button" wire:click="$set('type', 'debit')" @class(['dd-btn border-2', 'border-rose-400 bg-rose-50 text-rose-600' => $type === 'debit', 'border-transparent bg-slate-100 text-slate-500 dark:bg-slate-800' => $type !== 'debit'])>عليهم</button>
                <button type="button" wire:click="$set('type', 'credit')" @class(['dd-btn border-2', 'border-emerald-400 bg-emerald-50 text-emerald-600' => $type === 'credit', 'border-transparent bg-slate-100 text-slate-500 dark:bg-slate-800' => $type !== 'credit'])>لهم</button>
            </div>
            <input type="text" wire:model="notes" class="dd-input" placeholder="وصف الفاتورة (اختياري)">
        </div>

        <div class="dd-card p-5">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-bold">الأشخاص ({{ count($shares) }})</h2>
                @error('shares') <p class="text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>
            <input type="search" wire:model.live.debounce.300ms="search" class="dd-input mb-3" placeholder="بحث...">
            <div class="max-h-96 space-y-2 overflow-y-auto">
                @foreach ($this->accounts as $acc)
                    @php($on = array_key_exists($acc->id, $shares))
                    <div wire:key="sp-{{ $acc->id }}" @class(['flex items-center gap-3 rounded-2xl border p-3', 'border-slate-900 bg-slate-50 dark:border-slate-400 dark:bg-slate-800' => $on, 'border-slate-100 dark:border-slate-800' => ! $on])>
                        <button type="button" wire:click="toggle({{ $acc->id }})" class="flex flex-1 items-center gap-3 text-start">
                            <span @class(['flex size-6 items-center justify-center rounded-md border-2', 'border-slate-900 bg-slate-900 text-white' => $on, 'border-slate-300' => ! $on])>@if ($on)<flux:icon.check variant="micro" />@endif</span>
                            <span class="font-bold">{{ $acc->name }}</span>
                        </button>
                        @if ($on && $mode === 'custom')
                            <input type="text" inputmode="decimal" wire:model.live.debounce.400ms="shares.{{ $acc->id }}" class="dd-input w-32 py-2 text-left" dir="ltr" placeholder="0">
                        @elseif ($on)
                            <span class="font-bold tabular-nums text-slate-600">{{ number_format($preview[$acc->id] ?? 0, 2) }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
            @if ($mode === 'custom' && $shares)
                <p class="mt-3 text-left font-bold" dir="ltr">= {{ number_format(array_sum($preview), 2) }}</p>
            @endif
        </div>

        <button type="submit" class="dd-btn-primary w-full py-4 text-lg"><flux:icon.receipt-percent variant="mini" /> تسجيل التقسيم</button>
    </form>
</div>
