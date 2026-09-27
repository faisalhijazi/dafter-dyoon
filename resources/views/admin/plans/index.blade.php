@extends('admin.layout')

@section('title', 'الخطط')
@section('heading', 'خطط الاشتراك')

@php($input = 'w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm outline-none focus:border-indigo-400 focus:bg-white')

@section('content')
    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ($plans as $plan)
            <form method="POST" action="{{ route('admin.plans.update', $plan) }}" class="rounded-[32px] bg-white p-6 shadow-sm ring-1 ring-slate-100">
                @csrf
                @method('PUT')

                <div class="mb-5 flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-black">{{ $plan->name }}</h2>
                        <div class="text-xs text-slate-400" dir="ltr">{{ $plan->slug }}</div>
                    </div>
                    <span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-600">{{ number_format($plan->tenants_count) }} مشترك</span>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-600">الاسم</label>
                        <input name="name" value="{{ $plan->name }}" required maxlength="60" class="{{ $input }}">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-600">الوصف</label>
                        <input name="description" value="{{ $plan->description }}" maxlength="255" class="{{ $input }}">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-600">السعر الشهري ($)</label>
                        <input type="number" step="0.01" min="0" name="price_monthly" value="{{ (float) $plan->price_monthly }}" required dir="ltr" class="{{ $input }}">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-600">السعر السنوي ($)</label>
                        <input type="number" step="0.01" min="0" name="price_yearly" value="{{ (float) $plan->price_yearly }}" required dir="ltr" class="{{ $input }}">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-600">أقصى عدد حسابات</label>
                        <input type="number" min="1" name="max_accounts" value="{{ $plan->max_accounts }}" placeholder="غير محدود" dir="ltr" class="{{ $input }}">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-600">أقصى عدد معاملات</label>
                        <input type="number" min="1" name="max_transactions" value="{{ $plan->max_transactions }}" placeholder="غير محدود" dir="ltr" class="{{ $input }}">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-600">عدد الموظفين (فريق العمل)</label>
                        <input type="number" min="0" name="max_staff" value="{{ $plan->max_staff }}" placeholder="غير محدود" dir="ltr" class="{{ $input }}">
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                    @foreach (['multi_currency' => 'عملات متعددة', 'debt_limits' => 'سقوف التنبيه', 'cloud_backup' => 'نسخ سحابي', 'live_statement' => 'كشف حساب حي', 'is_active' => 'الخطة مفعّلة'] as $field => $label)
                        <label class="flex items-center gap-2 rounded-2xl bg-slate-50 px-3 py-2.5">
                            <input type="checkbox" name="{{ $field }}" value="1" @checked($plan->{$field}) class="size-4 rounded border-slate-300 text-indigo-600">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>

                <div class="mt-4">
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">المميزات المعروضة (ميزة في كل سطر)</label>
                    <textarea name="features_text" rows="5" class="{{ $input }}">{{ implode("\n", $plan->features ?? []) }}</textarea>
                </div>

                <div class="mt-4">
                    <label class="mb-1.5 block text-sm font-medium text-slate-600">العملات المجانية (رموز ISO مفصولة بفاصلة)</label>
                    <input name="free_currencies_text" value="{{ implode(', ', $plan->free_currencies ?? []) }}" placeholder="ILS, USD" dir="ltr" class="{{ $input }}">
                    <p class="mt-1 text-xs text-slate-400">تُستخدم عندما يكون خيار «عملات متعددة» غير مفعّل، والعملة الأساسية للمتجر متاحة دائماً. الرموز: ILS شيكل، USD دولار، JOD دينار، SAR ريال سعودي، EGP جنيه، YER ريال يمني.</p>
                </div>

                <button type="submit" class="mt-5 rounded-full bg-slate-900 px-6 py-2.5 text-sm font-bold text-white">حفظ الخطة</button>
            </form>
        @endforeach
    </div>
@endsection
