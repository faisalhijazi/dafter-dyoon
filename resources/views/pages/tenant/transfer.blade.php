<?php

use App\Models\Account;
use App\Services\CurrencyConverter;
use App\Services\LedgerService;
use App\Support\MathExpression;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('تحويل بين الحسابات')] class extends Component {
    public ?int $fromId = null;
    public ?int $toId = null;
    public string $amount = '';
    public ?int $currency_id = null;
    public string $notes = '';

    public function mount(CurrencyConverter $converter): void
    {
        $this->currency_id = $converter->base()?->id;
    }

    #[Computed]
    public function accounts()
    {
        return Account::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function currencies()
    {
        $converter = app(CurrencyConverter::class);

        return $converter->usable();
    }

    public function save(LedgerService $ledger, CurrencyConverter $converter)
    {
        $value = MathExpression::evaluate($this->amount);

        $this->validate([
            'fromId' => ['required', 'integer', 'different:toId', 'in:'.$this->accounts->pluck('id')->implode(',')],
            'toId' => ['required', 'integer', 'in:'.$this->accounts->pluck('id')->implode(',')],
            'amount' => ['required', fn ($a, $v, $fail) => ($value === null || $value <= 0) ? $fail('أدخل مبلغاً صحيحاً.') : null],
            'currency_id' => ['required', 'in:'.$this->currencies->pluck('id')->implode(',')],
            'notes' => ['nullable', 'string', 'max:500'],
        ], ['fromId.different' => 'لا يمكن التحويل إلى نفس الحساب.'], ['fromId' => 'من حساب', 'toId' => 'إلى حساب', 'amount' => 'المبلغ']);

        $from = Account::findOrFail($this->fromId);
        $to = Account::findOrFail($this->toId);
        $currency = $converter->find($this->currency_id);

        $ledger->transfer($from, $to, $value, $currency, $this->notes ?: null);

        session()->flash('success', 'تم تحويل '.$currency->format($value).' '.$currency->code.' من '.$from->name.' إلى '.$to->name.'.');

        return $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<div class="pb-16">
    <x-dd.topbar title="تحويل بين الحسابات" :back="route('dashboard')" />

    <form wire:submit="save" class="space-y-5 px-5">
        <div class="dd-card space-y-5 p-6">
            <p class="rounded-2xl bg-blue-50 p-4 text-sm text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">
                يُسجَّل المبلغ <b>عليه</b> في الحساب المحوِّل، و<b>له</b> في الحساب المحوَّل إليه، بنفس العملة والتاريخ.
            </p>
            <div>
                <label class="dd-label">من حساب *</label>
                <select wire:model="fromId" class="dd-input">
                    <option value="">— اختر —</option>
                    @foreach ($this->accounts as $acc)
                        <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                    @endforeach
                </select>
                @error('fromId') <p class="dd-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-center"><span class="flex size-12 items-center justify-center rounded-full bg-blue-500 text-white shadow-lg"><flux:icon.arrow-down variant="mini" /></span></div>
            <div>
                <label class="dd-label">إلى حساب *</label>
                <select wire:model="toId" class="dd-input">
                    <option value="">— اختر —</option>
                    @foreach ($this->accounts as $acc)
                        <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                    @endforeach
                </select>
                @error('toId') <p class="dd-error">{{ $message }}</p> @enderror
            </div>
            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="dd-label">المبلغ *</label>
                    <input type="text" inputmode="decimal" wire:model="amount" class="dd-input text-left text-xl font-bold" dir="ltr" placeholder="0.00">
                    @error('amount') <p class="dd-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="dd-label">العملة</label>
                    <select wire:model="currency_id" class="dd-input">
                        @foreach ($this->currencies as $cur)
                            <option value="{{ $cur->id }}">{{ $cur->code }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label class="dd-label">ملاحظات</label>
                <input type="text" wire:model="notes" class="dd-input" placeholder="اختياري">
            </div>
        </div>
        <button type="submit" class="dd-btn-primary w-full py-4 text-lg"><flux:icon.arrows-right-left variant="mini" /> تنفيذ التحويل</button>
    </form>
</div>
