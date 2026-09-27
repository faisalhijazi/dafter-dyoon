<?php

use App\Models\Currency;
use App\Services\CurrencyConverter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::tenant')] class extends Component {
    public ?Currency $currency = null;

    public string $code = '';
    public string $name = '';
    public string $exchange_rate = '';
    public int $decimal_places = 2;
    public bool $is_base = false;

    public function mount(?Currency $currency = null)
    {
        $tenant = auth()->user()->tenant;

        if (! $currency?->exists && ! $tenant->canUse('multi_currency')) {
            return $this->redirectRoute('tenant.upgrade', navigate: true);
        }

        if ($currency?->exists) {
            $this->currency = $currency;
            $this->code = $currency->code;
            $this->name = $currency->name;
            $this->exchange_rate = rtrim(rtrim(number_format((float) $currency->exchange_rate, 6, '.', ''), '0'), '.');
            $this->decimal_places = $currency->decimal_places;
            $this->is_base = $currency->is_base;
        }
    }

    public function save(CurrencyConverter $converter)
    {
        $tenantId = auth()->user()->tenant_id;

        $data = $this->validate([
            'code' => ['required', 'string', 'max:10', Rule::unique('currencies')->where('tenant_id', $tenantId)->ignore($this->currency?->id)],
            'name' => ['required', 'string', 'max:60'],
            'exchange_rate' => [$this->currency?->is_base ? 'nullable' : 'required', 'numeric', 'gt:0', 'max:1000000000'],
            'decimal_places' => ['required', Rule::in([0, 2])],
            'is_base' => ['boolean'],
        ], attributes: ['code' => 'الاسم المختصر', 'name' => 'الاسم الكامل', 'exchange_rate' => 'سعر الصرف', 'decimal_places' => 'الخانات العشرية']);

        $wasBase = (bool) $this->currency?->is_base;
        $attributes = [
            'code' => $data['code'],
            'name' => $data['name'],
            'decimal_places' => $data['decimal_places'],
            'exchange_rate' => $wasBase ? 1 : $data['exchange_rate'],
        ];

        if ($this->currency) {
            $this->currency->update($attributes);
            $currency = $this->currency;
        } else {
            $currency = Currency::create([...$attributes, 'is_base' => false, 'sort_order' => Currency::max('sort_order') + 1]);
        }

        $converter->flush();

        if ($this->is_base && ! $wasBase) {
            $converter->makeBase($currency->fresh());
        }

        session()->flash('success', 'تم حفظ العملة '.$currency->name.'.');

        return $this->redirectRoute('tenant.currencies', navigate: true);
    }

    public function render()
    {
        return $this->view()->title($this->currency ? 'تعديل عملة' : 'إضافة عملة جديدة');
    }
}; ?>

@php($base = app(\App\Services\CurrencyConverter::class)->base())
<div class="pb-16">
    <x-dd.topbar :title="$currency ? 'تعديل عملة' : 'إضافة عملة جديدة'" :back="route('tenant.currencies')">
        <button type="submit" form="currency-form" class="dd-icon-btn size-11 text-slate-900 dark:text-white" aria-label="حفظ"><flux:icon.bookmark-square variant="solid" class="size-8" /></button>
    </x-dd.topbar>

    <form id="currency-form" wire:submit="save" class="space-y-6 px-5">
        <section class="dd-card space-y-5 p-6">
            <h2 class="flex items-center gap-3 text-lg font-bold text-slate-500"><flux:icon.information-circle variant="solid" class="size-7 text-slate-400" /> معلومات العملة</h2>
            <div class="relative">
                <flux:icon.tag class="pointer-events-none absolute right-4 top-4 size-6 text-slate-500" />
                <input type="text" wire:model="code" placeholder="الاسم المختصر * (مثال: دولار أو USD)" class="dd-input pr-14 py-5 text-lg">
                @error('code') <p class="dd-error">{{ $message }}</p> @enderror
            </div>
            <div class="relative">
                <span class="pointer-events-none absolute right-4 top-4 text-lg font-extrabold text-slate-500">Tт</span>
                <input type="text" wire:model="name" placeholder="الاسم الكامل * (مثال: دولار أمريكي)" class="dd-input pr-14 py-5 text-lg">
                @error('name') <p class="dd-error">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="dd-card space-y-6 p-6">
            <h2 class="flex items-center gap-3 text-lg font-bold text-slate-500"><flux:icon.cog-8-tooth variant="solid" class="size-7 text-slate-400" /> إعدادات العملة</h2>
            <div class="relative">
                <label class="absolute -top-3 right-5 bg-white px-2 text-sm text-slate-500 dark:bg-slate-900">سعر الصرف *</label>
                <div class="flex items-center gap-3 rounded-2xl border border-slate-200 px-4 dark:border-slate-700">
                    <flux:icon.arrows-right-left class="size-6 text-slate-500" />
                    <input type="text" inputmode="decimal" wire:model="exchange_rate" @disabled($currency?->is_base) placeholder="0" class="flex-1 bg-transparent py-5 text-lg focus:outline-none disabled:text-slate-400" dir="ltr">
                </div>
                <p class="mt-1 text-sm text-slate-400">كم {{ $base?->code }} تساوي وحدة واحدة من هذه العملة.</p>
                @error('exchange_rate') <p class="dd-error">{{ $message }}</p> @enderror
            </div>
            <div class="relative">
                <label class="absolute -top-3 right-5 bg-white px-2 text-sm text-slate-500 dark:bg-slate-900">الخانات العشرية *</label>
                <div class="flex items-center gap-3 rounded-2xl border border-slate-200 px-4 dark:border-slate-700">
                    <span class="text-xs font-bold text-slate-500">123</span>
                    <select wire:model="decimal_places" class="flex-1 bg-transparent py-5 text-lg focus:outline-none">
                        <option value="2">منزلتان عشريتان</option>
                        <option value="0">بدون منازل عشرية</option>
                    </select>
                </div>
            </div>
            <label class="flex cursor-pointer items-center gap-4">
                <flux:icon.star variant="solid" class="size-7 text-slate-400" />
                <span class="flex-1 text-lg">تعيين كعملة افتراضية</span>
                <input type="checkbox" wire:model="is_base" @disabled($currency?->is_base) class="peer sr-only">
                <span class="relative h-10 w-20 rounded-full border-2 border-slate-200 bg-slate-100 transition after:absolute after:right-1.5 after:top-1/2 after:size-7 after:-translate-y-1/2 after:rounded-full after:bg-slate-300 after:transition peer-checked:border-emerald-300 peer-checked:bg-emerald-100 peer-checked:after:right-[2.6rem] peer-checked:after:bg-emerald-500 dark:border-slate-700 dark:bg-slate-800"></span>
            </label>
        </section>

        <button type="submit" class="dd-btn-primary w-full py-5 text-lg" wire:loading.attr="disabled">
            <flux:icon.bookmark-square variant="solid" /> حفظ العملة
        </button>
    </form>
</div>
