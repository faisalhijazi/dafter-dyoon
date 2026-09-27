<?php

use App\Models\Currency;
use App\Services\CurrencyConverter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('إدارة العملات')] class extends Component {
    public ?int $menuId = null;

    #[Computed]
    public function currencies()
    {
        return Currency::withCount('transactions')->orderByDesc('is_base')->orderBy('sort_order')->get();
    }

    public function makeBase(int $id, CurrencyConverter $converter): void
    {
        $currency = Currency::findOrFail($id);

        // Otherwise a free store could unlock any currency by making it the base.
        if (! auth()->user()->tenant->canUseCurrency($currency)) {
            $this->redirectRoute('tenant.upgrade', navigate: true);

            return;
        }

        $converter->makeBase($currency);
        $this->menuId = null;
        unset($this->currencies);
        $this->dispatch('toast', message: 'تم تعيين العملة الأساسية وإعادة احتساب أسعار الصرف.');
    }

    public function delete(int $id): void
    {
        $currency = Currency::withCount('transactions')->findOrFail($id);
        $this->menuId = null;

        if ($currency->is_base || $currency->transactions_count > 0) {
            $this->dispatch('toast', message: 'لا يمكن حذف العملة الأساسية أو عملة مستخدمة في معاملات.', type: 'error');

            return;
        }

        $currency->delete();
        unset($this->currencies);
        $this->dispatch('toast', message: 'تم حذف العملة.');
    }
}; ?>

@php($base = $this->currencies->firstWhere('is_base', true))
@php($tones = ['blue', 'pink', 'red', 'emerald', 'amber', 'violet', 'cyan', 'orange'])
@php($chip = [
    'blue' => 'border-blue-200 bg-blue-50 text-blue-600 dark:border-blue-500/20 dark:bg-blue-500/10',
    'pink' => 'border-pink-200 bg-pink-50 text-pink-600 dark:border-pink-500/20 dark:bg-pink-500/10',
    'red' => 'border-red-200 bg-red-50 text-red-600 dark:border-red-500/20 dark:bg-red-500/10',
    'emerald' => 'border-emerald-200 bg-emerald-50 text-emerald-600 dark:border-emerald-500/20 dark:bg-emerald-500/10',
    'amber' => 'border-amber-200 bg-amber-50 text-amber-600 dark:border-amber-500/20 dark:bg-amber-500/10',
    'violet' => 'border-violet-200 bg-violet-50 text-violet-600 dark:border-violet-500/20 dark:bg-violet-500/10',
    'cyan' => 'border-cyan-200 bg-cyan-50 text-cyan-600 dark:border-cyan-500/20 dark:bg-cyan-500/10',
    'orange' => 'border-orange-200 bg-orange-50 text-orange-600 dark:border-orange-500/20 dark:bg-orange-500/10',
])
<div class="pb-36">
    <x-dd.hero title="إدارة العملات" :subtitle="$this->currencies->count().' عملات متاحة'" :back="route('tenant.settings')" icon="currency-dollar">
        <x-slot:actions>
            <button type="button" wire:click="$refresh" class="dd-icon-btn bg-white/15 text-white" aria-label="تحديث"><flux:icon.arrow-path class="size-6" /></button>
        </x-slot:actions>
    </x-dd.hero>

    <div class="relative -mt-4 space-y-5 px-5">
        <section class="dd-card p-6">
            <div class="grid grid-cols-2 divide-x divide-x-reverse divide-slate-100 dark:divide-slate-800">
                <div class="flex items-center gap-4">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-500 dark:bg-emerald-500/10"><flux:icon.currency-dollar variant="solid" /></span>
                    <div><p class="text-slate-500">إجمالي العملات</p><p class="text-2xl font-bold">{{ $this->currencies->count() }}</p></div>
                </div>
                <div class="flex items-center gap-4 pr-4">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-amber-50 text-amber-500 dark:bg-amber-500/10"><flux:icon.star variant="solid" /></span>
                    <div><p class="text-slate-500">العملة الأساسية</p><p class="text-2xl font-bold">{{ $base?->code }}</p></div>
                </div>
            </div>
            <p class="mt-5 flex items-start gap-3 rounded-2xl bg-slate-100 p-4 text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                <flux:icon.information-circle class="mt-0.5 shrink-0" />
                اضغط على البطاقة للتعديل، أو على زر الخيارات لتعيينها أساسية أو حذفها.
            </p>
            @unless (auth()->user()->tenant->canUse('multi_currency'))
                <a href="{{ route('tenant.upgrade') }}" wire:navigate class="mt-3 flex items-center gap-3 rounded-2xl bg-amber-50 p-4 font-medium text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                    <flux:icon.lock-closed variant="mini" />
                    <span>خطتك الحالية تشمل <b>{{ app(\App\Services\CurrencyConverter::class)->usable()->pluck('name')->implode(' و') }}</b> مجاناً. باقي العملات متاحة في الخطة الاحترافية.</span>
                </a>
            @endunless
        </section>

        @foreach ($this->currencies as $i => $currency)
            @php($tone = $currency->is_base ? 'slate' : $tones[$i % count($tones)])
            <div wire:key="cur-{{ $currency->id }}" @class(['dd-card relative flex items-center gap-5 p-6', 'ring-2 ring-slate-300 dark:ring-slate-600' => $currency->is_base])>
                <a href="{{ route('tenant.currencies.edit', $currency) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-5">
                    <span class="relative flex size-20 shrink-0 items-center justify-center rounded-3xl text-lg font-bold shadow-lg {{ \App\Support\Palette::tile($tone === 'slate' ? 'sky' : $tone) }}">
                        {{ \Illuminate\Support\Str::limit($currency->code, 6, '') }}
                        @if ($currency->is_base)
                            <span class="absolute -right-1 -top-1 flex size-6 items-center justify-center rounded-full bg-amber-400 text-white ring-2 ring-white dark:ring-slate-900"><flux:icon.star variant="micro" class="size-3.5" /></span>
                        @endif
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="text-2xl font-bold">{{ $currency->name }}</span>
                            @unless (auth()->user()->tenant->canUseCurrency($currency))
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300"><flux:icon.lock-closed variant="micro" class="size-3" /> الاحترافية</span>
                            @endunless
                            @if ($currency->is_base)
                                <span class="rounded-full bg-linear-to-l from-slate-900 to-blue-500 px-3 py-0.5 text-xs text-white">أساسي</span>
                            @endif
                        </span>
                        @if ($currency->is_base)
                            <span class="mt-2 inline-flex items-center gap-1 rounded-full border border-slate-200 px-3 py-1 text-sm text-slate-500 dark:border-slate-700"><flux:icon.check-badge variant="micro" /> العملة المرجعية</span>
                        @else
                            <span dir="rtl" class="mt-2 inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm font-bold {{ $chip[$tone] }}">
                                <flux:icon.arrows-right-left variant="micro" /> 1 {{ $currency->code }} = {{ rtrim(rtrim(number_format((float) $currency->exchange_rate, 6, '.', ''), '0'), '.') }} {{ $base?->code }}
                            </span>
                        @endif
                        <span class="mt-2 flex items-center gap-2 text-sm text-slate-500"><flux:icon.hashtag variant="micro" /> المنازل العشرية: {{ $currency->decimal_places }}</span>
                    </span>
                </a>
                @unless ($currency->is_base)
                    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                        <button type="button" x-on:click="open = !open" class="dd-icon-btn size-10 bg-slate-100 text-slate-500 dark:bg-slate-800" aria-label="خيارات"><flux:icon.ellipsis-vertical variant="mini" /></button>
                        <div x-cloak x-show="open" x-transition class="absolute left-0 top-12 z-10 w-52 overflow-hidden rounded-2xl bg-white shadow-xl ring-1 ring-slate-100 dark:bg-slate-800 dark:ring-slate-700">
                            <button type="button" wire:click="makeBase({{ $currency->id }})" wire:confirm="تعيين {{ $currency->name }} كعملة أساسية؟ ستتم إعادة احتساب أسعار الصرف لكل العملات." class="flex w-full items-center gap-2 px-4 py-3 text-start hover:bg-slate-50 dark:hover:bg-slate-700"><flux:icon.star variant="mini" class="text-amber-500" /> تعيين كأساسية</button>
                            <button type="button" wire:click="delete({{ $currency->id }})" wire:confirm="حذف العملة {{ $currency->name }}؟" class="flex w-full items-center gap-2 px-4 py-3 text-start text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10"><flux:icon.trash variant="mini" /> حذف</button>
                        </div>
                    </div>
                @endunless
            </div>
        @endforeach
    </div>

    <a href="{{ route('tenant.currencies.create') }}" wire:navigate class="dd-fab">
        <flux:icon.plus class="size-7" /> إضافة عملة
    </a>
</div>
