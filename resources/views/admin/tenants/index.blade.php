@extends('admin.layout')

@section('title', 'المشتركون')
@section('heading', 'المشتركون')

@section('content')
    <form method="GET" class="mb-6 flex flex-wrap gap-3 rounded-[28px] bg-white p-4 shadow-sm ring-1 ring-slate-100">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="بحث باسم المتجر أو البريد..."
               class="min-w-56 flex-1 rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-sm outline-none focus:border-indigo-400 focus:bg-white">
        <select name="status" class="rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-sm">
            <option value="">كل الحالات</option>
            <option value="active" @selected(request('status') === 'active')>نشط</option>
            <option value="suspended" @selected(request('status') === 'suspended')>معطّل</option>
        </select>
        <select name="plan" class="rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-sm">
            <option value="">كل الخطط</option>
            @foreach ($plans as $plan)
                <option value="{{ $plan->id }}" @selected((string) request('plan') === (string) $plan->id)>{{ $plan->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="rounded-full bg-slate-900 px-5 py-2 text-sm font-bold text-white">تصفية</button>
        @if (request()->hasAny(['q', 'status', 'plan']))
            <a href="{{ route('admin.tenants.index') }}" class="rounded-full bg-slate-100 px-5 py-2 text-sm font-medium text-slate-600">مسح</a>
        @endif
    </form>

    <section class="rounded-[32px] bg-white p-5 shadow-sm ring-1 ring-slate-100">
        <div class="mb-4 text-sm text-slate-500">{{ number_format($tenants->total()) }} مشترك</div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-right text-sm">
                <thead class="text-slate-500">
                    <tr>
                        <th class="px-3 py-3 font-medium">المتجر</th>
                        <th class="px-3 py-3 font-medium">الخطة</th>
                        <th class="px-3 py-3 font-medium">الحالة</th>
                        <th class="px-3 py-3 font-medium">الحسابات</th>
                        <th class="px-3 py-3 font-medium">المعاملات</th>
                        <th class="px-3 py-3 font-medium">المستخدمون</th>
                        <th class="px-3 py-3 font-medium">ينتهي في</th>
                        <th class="px-3 py-3 font-medium">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tenants as $tenant)
                        <tr class="border-t border-slate-100">
                            <td class="px-3 py-3">
                                <a href="{{ route('admin.tenants.show', $tenant) }}" class="font-semibold text-slate-900 hover:text-indigo-600">{{ $tenant->name }}</a>
                                <div class="text-xs text-slate-400" dir="ltr">{{ $tenant->owner?->email }}</div>
                            </td>
                            <td class="px-3 py-3">@include('admin.partials.plan-badge')</td>
                            <td class="px-3 py-3">@include('admin.partials.status-badge')</td>
                            <td class="px-3 py-3">{{ number_format($tenant->accounts_count) }}</td>
                            <td class="px-3 py-3">{{ number_format($tenant->transactions_count) }}</td>
                            <td class="px-3 py-3">{{ number_format($tenant->users_count) }}</td>
                            <td class="px-3 py-3 text-slate-500">{{ $tenant->subscription_ends_at?->format('Y/m/d') ?? '—' }}</td>
                            <td class="px-3 py-3">
                                <div class="flex gap-2">
                                    <a href="{{ route('admin.tenants.show', $tenant) }}" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700">إدارة</a>
                                    <form method="POST" action="{{ route('admin.tenants.status', $tenant) }}" onsubmit="return confirm('{{ $tenant->isActive() ? 'تعطيل' : 'تفعيل' }} حساب {{ e($tenant->name) }}؟')">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="rounded-full px-3 py-1 text-xs font-medium {{ $tenant->isActive() ? 'bg-rose-100 text-rose-600' : 'bg-emerald-100 text-emerald-700' }}">{{ $tenant->isActive() ? 'تعطيل' : 'تفعيل' }}</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-3 py-6 text-center text-slate-500">لا توجد نتائج.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($tenants->hasPages())
            <div class="mt-5 flex items-center justify-between text-sm">
                @if ($tenants->onFirstPage())
                    <span class="rounded-full bg-slate-50 px-4 py-2 text-slate-300">السابق</span>
                @else
                    <a href="{{ $tenants->previousPageUrl() }}" class="rounded-full bg-slate-100 px-4 py-2 font-medium text-slate-700">السابق</a>
                @endif
                <span class="text-slate-500">صفحة {{ $tenants->currentPage() }} من {{ $tenants->lastPage() }}</span>
                @if ($tenants->hasMorePages())
                    <a href="{{ $tenants->nextPageUrl() }}" class="rounded-full bg-slate-100 px-4 py-2 font-medium text-slate-700">التالي</a>
                @else
                    <span class="rounded-full bg-slate-50 px-4 py-2 text-slate-300">التالي</span>
                @endif
            </div>
        @endif
    </section>
@endsection
