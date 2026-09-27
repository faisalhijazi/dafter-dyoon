<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('إعدادات التطبيق')] class extends Component {
    public bool $showName = false;
    public string $name = '';

    public function mount(): void
    {
        $this->name = auth()->user()->tenant->name;
    }

    public function saveName(): void
    {
        abort_unless(auth()->user()->role === 'owner', 403);

        $this->validate(['name' => ['required', 'string', 'max:255']], attributes: ['name' => 'اسم المتجر']);

        auth()->user()->tenant->update(['name' => $this->name]);
        $this->showName = false;
        $this->dispatch('toast', message: 'تم حفظ اسم المتجر.');
    }

    public function with(): array
    {
        return [
            'tenant' => auth()->user()->tenant,
            'isOwner' => auth()->user()->role === 'owner',
        ];
    }
}; ?>

<div class="pb-16">
    <x-dd.hero title="إعدادات التطبيق" subtitle="تخصيص المتجر والنظام" :back="route('dashboard')" icon="cog-6-tooth" />

    <div class="space-y-8 px-5 pt-8">
        <section class="dd-card divide-y divide-slate-100 px-5 dark:divide-slate-800">
            @if ($isOwner)
                <x-dd.menu-row icon="building-storefront" color="blue" :title="$tenant->name" subtitle="اسم المتجر" wire:click="$set('showName', true)" />
            @else
                <x-dd.menu-row icon="building-storefront" color="blue" :title="$tenant->name" subtitle="اسم المتجر" />
            @endif
            <x-dd.menu-row :href="route('tenant.upgrade')" icon="star" :color="$tenant->isPro() ? 'amber' : 'slate'" title="الخطة: {{ $tenant->effectivePlan()?->name ?? '—' }}" :subtitle="$tenant->isPro() && $tenant->subscription_ends_at ? 'سارية حتى '.$tenant->subscription_ends_at->format('Y/m/d') : 'عرض الخطط والترقية'" />
        </section>

        <section>
            <h2 class="dd-section-title mb-2 text-slate-500">الإدارة والنظام</h2>
            <div class="dd-card divide-y divide-slate-100 px-5 dark:divide-slate-800">
                <x-dd.menu-row :href="route('tenant.collections')" icon="banknotes" color="rose" title="التحصيل" subtitle="المستحقات، وعود السداد ودفعات الزبائن" />
                @can('view-reports')
                    <x-dd.menu-row :href="route('tenant.insights')" icon="presentation-chart-line" color="violet" title="الإحصائيات والتقارير" subtitle="رسوم بيانية، أعمار الديون والتقرير الشهري" />
                    <x-dd.menu-row :href="route('tenant.statement')" icon="chart-pie" color="teal" title="كشف الحسابات العام" subtitle="تقرير الأرصدة والطباعة" />
                @endcan
                @can('manage-settings')
                    <x-dd.menu-row :href="route('tenant.categories')" icon="square-3-stack-3d" color="indigo" title="إدارة الأقسام" subtitle="تصنيف الحسابات (عملاء، موردين...)" />
                    <x-dd.menu-row :href="route('tenant.currencies')" icon="currency-dollar" color="amber" title="إدارة العملات" subtitle="العملة الرئيسية وأسعار الصرف" />
                    <x-dd.menu-row :href="route('tenant.limits')" icon="shield-check" color="red" title="سقوف التنبيه" subtitle="الحد الأقصى لديون الحسابات" />
                @endcan
                @can('manage-team')
                    <x-dd.menu-row :href="route('tenant.team')" icon="user-group" color="sky" title="فريق العمل" subtitle="موظفون بصلاحيات تحددها أنت" />
                    <x-dd.menu-row :href="route('tenant.activity')" icon="clipboard-document-list" color="slate" title="سجل النشاط" subtitle="من سجّل أو عدّل أو حذف، ومتى" />
                @endcan
            </div>
        </section>

        <section>
            <h2 class="dd-section-title mb-2 text-slate-500">البيانات</h2>
            <div class="dd-card divide-y divide-slate-100 px-5 dark:divide-slate-800">
                @can('manage-settings')
                    <x-dd.menu-row :href="route('tenant.backup')" icon="circle-stack" color="emerald" title="النسخ الاحتياطي والاستعادة" subtitle="تنزيل نسخة أو الربط مع Google Drive" />
                @endcan
                @can('delete-records')
                    <x-dd.menu-row :href="route('tenant.trash')" icon="trash" color="rose" title="سلة المحذوفات" subtitle="استرجاع العناصر المحذوفة" />
                @endcan
                <x-dd.menu-row :href="route('tenant.offline')" :navigate="false" icon="signal-slash" color="slate" title="إدخال بدون إنترنت" subtitle="سجّل المعاملات وقت انقطاع الشبكة، وتُرفع تلقائياً عند عودتها" />
            </div>
        </section>

        <section class="dd-soft p-4">
            <h2 class="mb-3 flex items-center gap-2 font-medium text-slate-500"><flux:icon.sun variant="mini" /> مظهر التطبيق</h2>
            <x-dd.theme-switch />
        </section>

        <section>
            <h2 class="dd-section-title mb-2 text-slate-500">الحساب</h2>
            <div class="dd-card divide-y divide-slate-100 px-5 dark:divide-slate-800">
                <x-dd.menu-row :href="route('profile.edit')" icon="user-circle" color="violet" :title="auth()->user()->name" :subtitle="auth()->user()->email" />
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="flex w-full items-center gap-4 py-4 text-start">
                        <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl shadow-lg {{ \App\Support\Palette::tile('slate') }}"><flux:icon.arrow-left-start-on-rectangle variant="solid" class="size-7" /></span>
                        <span class="text-lg font-bold text-rose-600">تسجيل الخروج</span>
                    </button>
                </form>
            </div>
        </section>
    </div>

    @if ($isOwner)
        <x-dd.sheet model="showName" title="اسم المتجر">
            <form wire:submit="saveName" class="space-y-4">
                <div>
                    <label class="dd-label">اسم المتجر</label>
                    <input type="text" wire:model="name" class="dd-input text-xl font-bold">
                    @error('name') <p class="dd-error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="dd-btn-primary w-full">حفظ</button>
            </form>
        </x-dd.sheet>
    @endif
</div>
