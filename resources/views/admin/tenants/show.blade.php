@extends('admin.layout')

@section('title', $tenant->name)
@section('heading', $tenant->name)

@php($input = 'w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm outline-none focus:border-indigo-400 focus:bg-white')

@section('content')
    <a href="{{ route('admin.tenants.index') }}" class="mb-6 inline-flex items-center gap-1 text-sm font-medium text-indigo-600">→ كل المشتركين</a>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([['الحسابات', $tenant->accounts_count], ['المعاملات', $tenant->transactions_count], ['العملات', $tenant->currencies_count], ['الأقسام', $tenant->categories_count]] as [$label, $value])
            <div class="rounded-[28px] bg-white p-5 shadow-sm ring-1 ring-slate-100">
                <div class="text-sm text-slate-500">{{ $label }}</div>
                <div class="mt-2 text-2xl font-black">{{ number_format($value) }}</div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <section class="rounded-[32px] bg-white p-6 shadow-sm ring-1 ring-slate-100 lg:col-span-2">
            <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-black">بيانات الاشتراك</h2>
                <div class="flex gap-2">@include('admin.partials.plan-badge') @include('admin.partials.status-badge')</div>
            </div>

            <form method="POST" action="{{ route('admin.tenants.update', $tenant) }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                @method('PUT')

                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">اسم المتجر</label>
                    <input name="name" value="{{ old('name', $tenant->name) }}" required class="{{ $input }}">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">الخطة</label>
                    <select name="plan_id" class="{{ $input }}">
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->id }}" @selected((int) old('plan_id', $tenant->plan_id) === $plan->id)>{{ $plan->name }}{{ $plan->is_active ? '' : ' (غير مفعّلة)' }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">الحالة</label>
                    <select name="status" class="{{ $input }}">
                        <option value="active" @selected(old('status', $tenant->status) === 'active')>نشط</option>
                        <option value="suspended" @selected(old('status', $tenant->status) === 'suspended')>معطّل</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">تاريخ انتهاء الاشتراك</label>
                    <input type="date" name="subscription_ends_at" value="{{ old('subscription_ends_at', $tenant->subscription_ends_at?->format('Y-m-d')) }}" class="{{ $input }}">
                    <p class="mt-1 text-xs text-slate-400">اتركه فارغاً لاشتراك بلا تاريخ انتهاء.</p>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">أو التمديد لمدة</label>
                    <select name="extend_months" class="{{ $input }}">
                        <option value="">— بدون تمديد —</option>
                        @foreach ([1 => 'شهر', 3 => '3 أشهر', 6 => '6 أشهر', 12 => 'سنة', 24 => 'سنتان'] as $months => $label)
                            <option value="{{ $months }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-400">يُضاف من تاريخ الانتهاء الحالي إن كان سارياً، وإلا من اليوم.</p>
                </div>
                <div class="sm:col-span-2">
                    <button type="submit" class="rounded-full bg-slate-900 px-6 py-2.5 text-sm font-bold text-white">حفظ التغييرات</button>
                </div>
            </form>
        </section>

        <div class="space-y-6">
            <section class="rounded-[32px] bg-white p-6 shadow-sm ring-1 ring-slate-100">
                <h2 class="mb-4 text-xl font-black">المستخدمون</h2>
                <div class="space-y-3">
                    @foreach ($tenant->users as $user)
                        <div class="rounded-2xl bg-slate-50 px-4 py-3 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-semibold">{{ $user->name }}</span>
                                <span class="rounded-full bg-white px-2 py-0.5 text-xs text-slate-500 ring-1 ring-slate-200">{{ $user->role === 'owner' ? 'المالك' : $user->role }}</span>
                            </div>
                            <div class="mt-1 text-xs text-slate-400" dir="ltr">{{ $user->email }}</div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="rounded-[32px] bg-white p-6 shadow-sm ring-1 ring-slate-100 text-sm">
                <h2 class="mb-4 text-xl font-black">معلومات</h2>
                <dl class="space-y-2">
                    <div class="flex justify-between"><dt class="text-slate-500">المعرّف</dt><dd dir="ltr">#{{ $tenant->id }} · {{ $tenant->slug }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">تاريخ التسجيل</dt><dd>{{ $tenant->created_at->format('Y/m/d') }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Google Drive</dt><dd dir="ltr">{{ $tenant->google_email ?? 'غير مربوط' }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-slate-500">Stripe</dt>
                        <dd dir="ltr">
                            @if ($tenant->stripe_customer_id)
                                <a href="https://dashboard.stripe.com/customers/{{ $tenant->stripe_customer_id }}" target="_blank" rel="noopener" class="text-indigo-600">{{ $tenant->stripe_customer_id }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    @if ($tenant->subscription_status)
                        <div class="flex justify-between"><dt class="text-slate-500">حالة الاشتراك</dt><dd>{{ $tenant->subscription_status }}{{ $tenant->subscription_cancels ? ' (يُلغى بنهاية الفترة)' : '' }}</dd></div>
                    @endif
                </dl>
                @if ($payments->isNotEmpty())
                    <h3 class="mb-2 mt-5 font-bold">دفعات Stripe</h3>
                    <div class="space-y-1.5">
                        @foreach ($payments as $payment)
                            <div class="flex justify-between rounded-xl bg-slate-50 px-3 py-2">
                                <span class="text-slate-500">{{ $payment->created_at->format('Y/m/d') }} · {{ $payment->status === 'paid' ? 'مدفوعة' : 'فشلت' }}</span>
                                <span dir="ltr" class="font-bold">{{ $payment->formattedAmount() }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </div>

    <section class="mt-6 rounded-[32px] bg-white p-6 shadow-sm ring-1 ring-slate-100">
        <h2 class="mb-4 text-xl font-black">آخر عمليات النسخ الاحتياطي</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-right text-sm">
                <thead class="text-slate-500">
                    <tr>
                        <th class="px-3 py-2 font-medium">التاريخ</th>
                        <th class="px-3 py-2 font-medium">العملية</th>
                        <th class="px-3 py-2 font-medium">المزوّد</th>
                        <th class="px-3 py-2 font-medium">الملف</th>
                        <th class="px-3 py-2 font-medium">الحجم</th>
                        <th class="px-3 py-2 font-medium">الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($backups as $log)
                        <tr class="border-t border-slate-100">
                            <td class="px-3 py-2 text-slate-500">{{ $log->created_at->format('Y/m/d H:i') }}</td>
                            <td class="px-3 py-2">{{ $log->action }}</td>
                            <td class="px-3 py-2">{{ $log->provider }}</td>
                            <td class="px-3 py-2 text-xs" dir="ltr">{{ $log->file_name }}</td>
                            <td class="px-3 py-2">{{ $log->size_bytes ? \Illuminate\Support\Number::fileSize($log->size_bytes) : '—' }}</td>
                            <td class="px-3 py-2" title="{{ $log->message }}">{{ $log->status }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-slate-500">لا توجد عمليات بعد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
