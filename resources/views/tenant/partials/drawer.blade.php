@php($tenant = auth()->user()->tenant)
{{-- Side drawer (slides in from the right in RTL), opened with $dispatch('open-drawer') --}}
<div x-cloak x-show="drawer" class="fixed inset-0 z-50 no-print" role="dialog" aria-modal="true">
    <div x-show="drawer" x-transition.opacity class="absolute inset-0 bg-slate-900/50 backdrop-blur-[2px]" x-on:click="drawer = false"></div>

    <aside x-show="drawer"
           x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
           x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
           class="absolute inset-y-0 right-0 flex w-[85%] max-w-sm flex-col overflow-hidden rounded-l-[2rem] bg-white shadow-2xl dark:bg-slate-900">

        <div class="relative shrink-0 overflow-hidden bg-linear-to-br from-slate-900 via-slate-800 to-indigo-950 px-6 pb-8 pt-10 text-white">
            <div class="absolute -left-10 -top-10 size-40 rounded-full bg-white/5"></div>
            <div class="absolute -bottom-16 left-10 size-40 rounded-full bg-white/5"></div>
            <x-brand.logo tone="light" class="relative h-16" />
            <p class="relative mt-4 text-white/70">{{ $tenant->name }}</p>
            <span class="relative mt-3 inline-flex items-center gap-1 rounded-full bg-white/10 px-3 py-1 text-xs font-bold">
                <flux:icon.star variant="micro" class="text-amber-400" />
                الخطة: {{ $tenant->effectivePlan()?->name ?? '—' }}
            </span>
        </div>

        <nav class="flex-1 space-y-6 overflow-y-auto px-5 py-6">
            <section>
                <h3 class="dd-section-title mb-4 text-slate-500">إجراءات سريعة</h3>
                <div class="grid grid-cols-3 gap-3">
                    <a href="{{ route('tenant.accounts.create') }}" wire:navigate class="flex flex-col items-center gap-2 rounded-3xl border border-slate-200 bg-slate-100 px-2 py-4 text-center text-sm font-bold dark:border-slate-700 dark:bg-slate-800">
                        <span class="flex size-12 items-center justify-center rounded-2xl {{ \App\Support\Palette::tile('dark') }} shadow-lg"><flux:icon.user-plus variant="solid" class="size-6" /></span>
                        شخص جديد
                    </a>
                    <a href="{{ route('tenant.collections') }}" wire:navigate class="flex flex-col items-center gap-2 rounded-3xl border border-rose-200 bg-rose-50 px-2 py-4 text-center text-sm font-bold dark:border-rose-500/20 dark:bg-rose-500/10">
                        <span class="flex size-12 items-center justify-center rounded-2xl {{ \App\Support\Palette::tile('rose') }} shadow-lg"><flux:icon.banknotes variant="solid" class="size-6" /></span>
                        التحصيل
                    </a>
                    @can('view-reports')
                        <a href="{{ route('tenant.insights') }}" wire:navigate class="flex flex-col items-center gap-2 rounded-3xl border border-violet-200 bg-violet-50 px-2 py-4 text-center text-sm font-bold dark:border-violet-500/20 dark:bg-violet-500/10">
                            <span class="flex size-12 items-center justify-center rounded-2xl {{ \App\Support\Palette::tile('violet') }} shadow-lg"><flux:icon.presentation-chart-line variant="solid" class="size-6" /></span>
                            الإحصائيات
                        </a>
                    @else
                        <a href="{{ route('tenant.quick-entry') }}" wire:navigate class="flex flex-col items-center gap-2 rounded-3xl border border-amber-200 bg-amber-50 px-2 py-4 text-center text-sm font-bold dark:border-amber-500/20 dark:bg-amber-500/10">
                            <span class="flex size-12 items-center justify-center rounded-2xl {{ \App\Support\Palette::tile('amber') }} shadow-lg"><flux:icon.bolt variant="solid" class="size-6" /></span>
                            إدخال سريع
                        </a>
                    @endcan
                </div>
            </section>

            <section class="border-t border-slate-100 pt-6 dark:border-slate-800">
                <h3 class="dd-section-title mb-2 text-slate-500">الإدارة والنظام</h3>
                <x-dd.menu-row :href="route('tenant.assistant')" icon="sparkles" color="indigo" title="مساعد AI" compact />
                @can('view-reports')
                    <x-dd.menu-row :href="route('tenant.statement')" icon="chart-pie" color="teal" title="كشف الحسابات العام" compact />
                @endcan
                @can('manage-settings')
                    <x-dd.menu-row :href="route('tenant.categories')" icon="square-3-stack-3d" color="indigo" title="إدارة الأقسام" compact />
                    <x-dd.menu-row :href="route('tenant.currencies')" icon="currency-dollar" color="amber" title="العملات" compact />
                    <x-dd.menu-row :href="route('tenant.limits')" icon="shield-check" color="red" title="سقوف التنبيه" compact />
                    <x-dd.menu-row :href="route('tenant.backup')" icon="arrow-down-tray" color="emerald" title="النسخ الاحتياطي" compact />
                @endcan
                @can('manage-team')
                    <x-dd.menu-row :href="route('tenant.team')" icon="user-group" color="sky" title="فريق العمل" compact />
                    <x-dd.menu-row :href="route('tenant.activity')" icon="clipboard-document-list" color="slate" title="سجل النشاط" compact />
                @endcan
                <x-dd.menu-row :href="route('tenant.offline')" :navigate="false" icon="signal-slash" color="slate" title="إدخال بدون إنترنت" compact />
                <x-dd.menu-row :href="route('tenant.settings')" icon="cog-6-tooth" color="slate" title="إعدادات التطبيق" compact />
            </section>

            <section class="dd-soft p-4">
                <h3 class="mb-3 flex items-center gap-2 font-medium text-slate-500"><flux:icon.sun variant="mini" /> مظهر التطبيق</h3>
                <x-dd.theme-switch />
            </section>
        </nav>

        <div class="grid shrink-0 grid-cols-2 gap-3 border-t border-slate-100 p-5 dark:border-slate-800">
            <a href="{{ route('profile.edit') }}" class="flex flex-col items-center gap-2 rounded-3xl border border-indigo-200 bg-indigo-50 py-3 text-sm font-bold dark:border-indigo-500/20 dark:bg-indigo-500/10">
                <span class="flex size-10 items-center justify-center rounded-xl {{ \App\Support\Palette::tile('indigo') }}"><flux:icon.user-circle variant="solid" class="size-5" /></span>
                {{ \Illuminate\Support\Str::limit(auth()->user()->name, 16) }}
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="flex w-full flex-col items-center gap-2 rounded-3xl border border-rose-200 bg-rose-50 py-3 text-sm font-bold dark:border-rose-500/20 dark:bg-rose-500/10">
                    <span class="flex size-10 items-center justify-center rounded-xl {{ \App\Support\Palette::tile('rose') }}"><flux:icon.arrow-left-start-on-rectangle variant="solid" class="size-5" /></span>
                    تسجيل الخروج
                </button>
            </form>
        </div>
    </aside>
</div>
