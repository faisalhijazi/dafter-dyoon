@extends('admin.layout')

@section('title', 'الرئيسية')
@section('heading', 'إحصائيات المنصة')

@section('content')
    <div class="space-y-6">
        {{-- Headline numbers --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['إجمالي المشتركين', number_format($stats['tenants']), 'text-slate-900', number_format($stats['active']).' نشط · '.number_format($stats['suspended']).' معطّل'],
                ['مشتركو الخطط المدفوعة', number_format($stats['pro']), 'text-amber-600', $stats['tenants'] ? round($stats['pro'] / $stats['tenants'] * 100).'% من المشتركين' : '—'],
                ['الإيراد الشهري المتكرر', '$'.number_format($stats['mrr'], 2), 'text-emerald-600', 'من الاشتراكات السارية'],
                ['معاملات آخر 30 يوماً', number_format($stats['transactions_30d']), 'text-indigo-600', number_format($stats['transactions']).' معاملة إجمالاً'],
            ] as [$label, $value, $tone, $hint])
                <div class="rounded-[28px] bg-white p-5 shadow-sm ring-1 ring-slate-100">
                    <div class="text-sm text-slate-500">{{ $label }}</div>
                    <div class="mt-3 text-3xl font-black {{ $tone }}">{{ $value }}</div>
                    <div class="mt-1 text-xs text-slate-400">{{ $hint }}</div>
                </div>
            @endforeach
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Latest tenants --}}
            <section class="rounded-[32px] bg-white p-5 shadow-sm ring-1 ring-slate-100 lg:col-span-2">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="text-lg font-black">أحدث المشتركين</h2>
                    <a href="{{ route('admin.tenants.index') }}" class="rounded-full bg-indigo-50 px-3 py-1.5 text-sm font-medium text-indigo-600">عرض الكل</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-right text-sm">
                        <thead class="text-slate-500">
                            <tr>
                                <th class="px-3 py-3 font-medium">المتجر</th>
                                <th class="px-3 py-3 font-medium">الخطة</th>
                                <th class="px-3 py-3 font-medium">الحالة</th>
                                <th class="px-3 py-3 font-medium">الحسابات</th>
                                <th class="px-3 py-3 font-medium">انضم</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($recentTenants as $tenant)
                                <tr class="text-slate-700">
                                    <td class="px-3 py-3">
                                        <a href="{{ route('admin.tenants.show', $tenant) }}" class="font-bold text-slate-900 hover:text-indigo-600">{{ $tenant->name }}</a>
                                        <div class="text-xs text-slate-400" dir="ltr">{{ $tenant->owner?->email }}</div>
                                    </td>
                                    <td class="px-3 py-3">@include('admin.partials.plan-badge', ['tenant' => $tenant])</td>
                                    <td class="px-3 py-3">@include('admin.partials.status-badge', ['tenant' => $tenant])</td>
                                    <td class="px-3 py-3">{{ number_format($tenant->accounts_count) }}</td>
                                    <td class="px-3 py-3 text-slate-500">{{ $tenant->created_at?->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="px-3 py-8 text-center text-slate-400">لا يوجد مشتركون بعد.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="space-y-6">
                {{-- Plans distribution --}}
                <section class="rounded-[32px] bg-white p-5 shadow-sm ring-1 ring-slate-100">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-lg font-black">توزيع الخطط</h2>
                        <a href="{{ route('admin.plans.index') }}" class="text-sm font-medium text-indigo-600">إدارة الخطط</a>
                    </div>
                    <div class="space-y-3">
                        @foreach ($plans as $plan)
                            @php($share = $stats['tenants'] ? $plan->tenants_count / $stats['tenants'] * 100 : 0)
                            <div>
                                <div class="flex justify-between text-sm">
                                    <span class="font-bold">{{ $plan->name }}</span>
                                    <span class="text-slate-500">{{ number_format($plan->tenants_count) }} مشترك</span>
                                </div>
                                <div class="mt-1.5 h-2.5 overflow-hidden rounded-full bg-slate-100">
                                    <div @class(['h-full rounded-full', 'bg-slate-400' => $plan->isFree(), 'bg-amber-500' => ! $plan->isFree()]) style="width: {{ round($share) }}%"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>

                {{-- Subscriptions ending soon --}}
                <section class="rounded-[32px] bg-white p-5 shadow-sm ring-1 ring-slate-100">
                    <h2 class="mb-4 text-lg font-black">اشتراكات تنتهي خلال 14 يوماً</h2>
                    <div class="divide-y divide-slate-100">
                        @forelse ($expiring as $tenant)
                            <a href="{{ route('admin.tenants.show', $tenant) }}" class="flex items-center justify-between py-2.5 text-sm hover:text-indigo-600">
                                <span class="font-bold">{{ $tenant->name }}</span>
                                <span class="rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">{{ $tenant->subscription_ends_at->diffForHumans() }}</span>
                            </a>
                        @empty
                            <p class="py-4 text-center text-sm text-slate-400">لا توجد اشتراكات قريبة الانتهاء.</p>
                        @endforelse
                    </div>
                </section>

                {{-- Platform usage --}}
                <section class="rounded-[32px] bg-white p-5 shadow-sm ring-1 ring-slate-100">
                    <h2 class="mb-4 text-lg font-black">استخدام المنصة</h2>
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex justify-between"><dt class="text-slate-500">الحسابات (عملاء وموردون)</dt><dd class="font-bold">{{ number_format($stats['accounts']) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">إجمالي المعاملات</dt><dd class="font-bold">{{ number_format($stats['transactions']) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-slate-500">نسخ احتياطية (30 يوماً)</dt><dd class="font-bold">{{ number_format($stats['backups_30d']) }}</dd></div>
                    </dl>
                    <a href="{{ route('admin.assistant.index') }}" class="mt-4 block rounded-2xl bg-indigo-50 px-4 py-3 text-center text-sm font-bold text-indigo-700">تعلّم مساعد AI ←</a>
                </section>
            </div>
        </div>
    </div>
@endsection
