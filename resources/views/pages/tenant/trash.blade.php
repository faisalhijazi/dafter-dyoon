<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Services\LedgerService;
use App\Support\Palette;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('سلة المحذوفات')] class extends Component {
    public string $tab = 'transactions';

    #[Computed]
    public function accounts()
    {
        return Account::onlyTrashed()->latest('deleted_at')->get();
    }

    #[Computed]
    public function transactions()
    {
        return Transaction::onlyTrashed()->with('account', 'currency')->latest('deleted_at')->take(100)->get();
    }

    public function restoreAccount(int $id): void
    {
        Account::onlyTrashed()->findOrFail($id)->restore();
        unset($this->accounts);
        $this->dispatch('toast', message: 'تم استرجاع الحساب.');
    }

    public function restoreTransaction(int $id, LedgerService $ledger): void
    {
        $tx = Transaction::onlyTrashed()->findOrFail($id);
        $tx->restore();
        $ledger->touchActivity($tx->account);
        unset($this->transactions);
        $this->dispatch('toast', message: 'تم استرجاع المعاملة.');
    }

    public function purgeAccount(int $id): void
    {
        Account::onlyTrashed()->findOrFail($id)->forceDelete();
        unset($this->accounts);
        $this->dispatch('toast', message: 'تم الحذف نهائياً.');
    }

    public function purgeTransaction(int $id): void
    {
        Transaction::onlyTrashed()->findOrFail($id)->forceDelete();
        unset($this->transactions);
        $this->dispatch('toast', message: 'تم الحذف نهائياً.');
    }

    public function emptyTrash(): void
    {
        Transaction::onlyTrashed()->forceDelete();
        Account::onlyTrashed()->get()->each->forceDelete();
        unset($this->accounts, $this->transactions);
        $this->dispatch('toast', message: 'تم إفراغ سلة المحذوفات.');
    }
}; ?>

<div class="pb-16">
    <x-dd.hero title="سلة المحذوفات" subtitle="استرجاع العناصر المحذوفة" :back="route('tenant.settings')" icon="trash" />

    <div class="space-y-5 px-5 pt-6">
        <div class="grid grid-cols-2 gap-2 rounded-2xl bg-white p-1.5 shadow-sm dark:bg-slate-900">
            @foreach (['transactions' => 'المعاملات ('.$this->transactions->count().')', 'accounts' => 'الحسابات ('.$this->accounts->count().')'] as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @class(['rounded-xl py-3 font-bold', 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' => $tab === $key, 'text-slate-500' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </div>

        @if ($tab === 'transactions')
            @forelse ($this->transactions as $tx)
                <div wire:key="ttx-{{ $tx->id }}" class="dd-card flex items-center gap-4 p-4">
                    <span @class(['flex size-12 items-center justify-center rounded-xl text-white', 'bg-emerald-500' => $tx->isCredit(), 'bg-rose-500' => ! $tx->isCredit()])><flux:icon :name="$tx->isCredit() ? 'arrow-up' : 'arrow-down'" variant="mini" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="font-bold">{{ $tx->account?->name }} — {{ $tx->isCredit() ? 'له' : 'عليه' }} {{ $tx->currency->format($tx->amount) }} {{ $tx->currency->code }}</p>
                        <p class="text-sm text-slate-500">حُذفت {{ $tx->deleted_at->diffForHumans() }}{{ $tx->account?->trashed() ? ' — الحساب محذوف أيضاً' : '' }}</p>
                    </div>
                    <button type="button" wire:click="restoreTransaction({{ $tx->id }})" class="dd-icon-btn size-11 bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10" title="استرجاع"><flux:icon.arrow-uturn-left variant="mini" /></button>
                    <button type="button" wire:click="purgeTransaction({{ $tx->id }})" wire:confirm="حذف نهائي؟ لا يمكن التراجع." class="dd-icon-btn size-11 bg-rose-50 text-rose-600 dark:bg-rose-500/10" title="حذف نهائي"><flux:icon.trash variant="mini" /></button>
                </div>
            @empty
                <p class="py-16 text-center text-slate-500">لا توجد معاملات محذوفة.</p>
            @endforelse
        @else
            @forelse ($this->accounts as $acc)
                <div wire:key="tac-{{ $acc->id }}" class="dd-card flex items-center gap-4 p-4">
                    <span class="flex size-12 items-center justify-center rounded-xl text-xl {{ Palette::avatar($acc->id) }}">{{ $acc->initial() }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-bold">{{ $acc->name }}</p>
                        <p class="text-sm text-slate-500">حُذف {{ $acc->deleted_at->diffForHumans() }}</p>
                    </div>
                    <button type="button" wire:click="restoreAccount({{ $acc->id }})" class="dd-icon-btn size-11 bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10" title="استرجاع"><flux:icon.arrow-uturn-left variant="mini" /></button>
                    <button type="button" wire:click="purgeAccount({{ $acc->id }})" wire:confirm="حذف الحساب وجميع معاملاته نهائياً؟" class="dd-icon-btn size-11 bg-rose-50 text-rose-600 dark:bg-rose-500/10" title="حذف نهائي"><flux:icon.trash variant="mini" /></button>
                </div>
            @empty
                <p class="py-16 text-center text-slate-500">لا توجد حسابات محذوفة.</p>
            @endforelse
        @endif

        @if ($this->accounts->isNotEmpty() || $this->transactions->isNotEmpty())
            <button type="button" wire:click="emptyTrash" wire:confirm="إفراغ السلة وحذف كل العناصر نهائياً؟" class="dd-btn w-full border border-rose-200 text-rose-600">إفراغ السلة</button>
        @endif
    </div>
</div>
