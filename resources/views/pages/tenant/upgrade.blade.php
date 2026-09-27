<?php

use App\Models\Plan;
use App\Services\StripeBilling;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('خطط الاشتراك')] class extends Component {
    public function with(StripeBilling $stripe): array
    {
        $tenant = auth()->user()->tenant;

        return [
            'tenant' => $tenant,
            'current' => $tenant->effectivePlan(),
            'plans' => Plan::where('is_active', true)->orderBy('sort_order')->get(),
            'usage' => [
                'accounts' => $tenant->accounts()->count(),
                'transactions' => $tenant->transactions()->count(),
            ],
            'online' => $stripe->isConfigured(),
            'canBill' => auth()->user()->can('manage-billing'),
            // The stored end date carries a short grace period; show the real billing date.
            'renewsOn' => $tenant->subscription_ends_at?->subDays(StripeBilling::GRACE_DAYS),
            'payments' => $tenant->subscriptionPayments()->latest()->take(12)->get(),
        ];
    }
}; ?>

@php($money = fn ($v) => '$'.rtrim(rtrim((string) $v, '0'), '.'))

<div class="pb-16" x-data="{ interval: 'month' }">
    <x-dd.hero title="خطط الاشتراك" subtitle="اختر الخطة المناسبة لنشاطك" :back="route('tenant.settings')" icon="trophy" />

    <div class="relative -mt-4 space-y-5 px-5">
        {{-- Active Stripe subscription --}}
        @if ($tenant->hasStripeSubscription())
            <section class="dd-card p-6">
                <div class="flex items-start gap-4">
                    <span @class(['flex size-12 shrink-0 items-center justify-center rounded-2xl',
                        'bg-rose-50 text-rose-600 dark:bg-rose-500/10' => $tenant->subscription_status === 'past_due',
                        'bg-amber-50 text-amber-600 dark:bg-amber-500/10' => $tenant->subscription_status !== 'past_due'])>
                        <flux:icon :name="$tenant->subscription_status === 'past_due' ? 'exclamation-triangle' : 'credit-card'" />
                    </span>
                    <div class="flex-1">
                        <h2 class="text-lg font-bold">اشتراك {{ $tenant->plan?->name }} — {{ StripeBilling::INTERVALS[$tenant->subscription_interval] ?? '' }}</h2>
                        @if ($tenant->subscription_status === 'past_due')
                            <p class="mt-1 text-sm text-rose-600">تعذّر تحصيل الدفعة الأخيرة. حدّث بطاقتك حتى لا تعود للخطة المجانية.</p>
                        @elseif ($tenant->subscription_cancels)
                            <p class="mt-1 text-sm text-slate-500">ملغى — يبقى فعّالاً حتى {{ $renewsOn?->format('Y/m/d') }} ثم تعود للخطة المجانية.</p>
                        @else
                            <p class="mt-1 text-sm text-slate-500">يتجدد تلقائياً في {{ $renewsOn?->format('Y/m/d') }}</p>
                        @endif
                    </div>
                </div>
                @if ($canBill)
                    <form method="POST" action="{{ route('tenant.billing.portal') }}" class="mt-5">
                        @csrf
                        <button type="submit" class="dd-btn w-full bg-slate-900 text-white dark:bg-white dark:text-slate-900">
                            <flux:icon.cog-6-tooth variant="micro" /> إدارة الاشتراك: البطاقة، الفواتير، الإلغاء
                        </button>
                    </form>
                @endif
            </section>
        @endif

        <section class="dd-card p-6">
            <h2 class="font-bold text-slate-500">استخدامك الحالي — خطة {{ $current?->name }}</h2>
            @foreach (['accounts' => 'الحسابات', 'transactions' => 'المعاملات'] as $key => $label)
                @php($max = $current?->{'max_'.$key})
                <div class="mt-4">
                    <div class="flex justify-between text-sm"><span>{{ $label }}</span><span class="tabular-nums">{{ $usage[$key] }} / {{ $max ?? '∞' }}</span></div>
                    <div class="mt-1.5 h-2.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div @class(['h-full rounded-full', 'bg-rose-500' => $max && $usage[$key] >= $max, 'bg-emerald-500' => ! $max || $usage[$key] < $max]) style="width: {{ $max ? min(100, round($usage[$key] / $max * 100)) : 8 }}%"></div>
                    </div>
                </div>
            @endforeach
        </section>

        {{-- Monthly / yearly --}}
        @if ($online && ! $tenant->hasStripeSubscription() && $plans->contains(fn ($p) => ! $p->isFree()))
            <div class="flex justify-center">
                <div class="inline-flex rounded-full bg-white p-1 shadow-sm ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800">
                    @foreach (StripeBilling::INTERVALS as $key => $label)
                        <button type="button" x-on:click="interval = '{{ $key }}'" :class="interval === '{{ $key }}' ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900' : 'text-slate-500'" class="rounded-full px-5 py-2 text-sm font-bold">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
        @endif

        @foreach ($plans as $plan)
            @php($isCurrent = $current?->is($plan))
            @php($yearly = (float) $plan->price_yearly > 0)
            @php($saving = $yearly && (float) $plan->price_monthly > 0 ? (int) round(100 - $plan->price_yearly / ($plan->price_monthly * 12) * 100) : 0)
            <section @class(['dd-card relative overflow-hidden p-6', 'ring-2 ring-amber-400' => ! $plan->isFree()])>
                @unless ($plan->isFree())
                    <span class="absolute left-5 top-5 rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-700">الأكثر طلباً</span>
                @endunless
                <h3 class="text-2xl font-extrabold">{{ $plan->name }}</h3>
                <p class="text-slate-500">{{ $plan->description }}</p>

                @if ($plan->isFree())
                    <p class="mt-4 text-4xl font-extrabold">مجاناً</p>
                @else
                    <p class="mt-4 text-4xl font-extrabold" x-show="interval === 'month' || {{ $yearly ? 'false' : 'true' }}">{{ $money($plan->price_monthly) }} <span class="text-base font-medium text-slate-500">/ شهرياً</span></p>
                    @if ($yearly)
                        <p class="mt-4 text-4xl font-extrabold" x-show="interval === 'year'" x-cloak>{{ $money($plan->price_yearly) }} <span class="text-base font-medium text-slate-500">/ سنوياً</span>
                            @if ($saving > 0) <span class="ms-1 rounded-full bg-emerald-50 px-2.5 py-1 align-middle text-xs font-bold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">وفّر {{ $saving }}%</span> @endif
                        </p>
                    @endif
                @endif

                <ul class="mt-5 space-y-2">
                    @foreach ($plan->features ?? [] as $feature)
                        <li class="flex items-center gap-2"><flux:icon.check-circle variant="mini" class="text-emerald-500" /> {{ $feature }}</li>
                    @endforeach
                </ul>

                @if ($isCurrent)
                    <div class="dd-btn mt-6 w-full bg-slate-100 text-slate-500 dark:bg-slate-800">خطتك الحالية</div>
                @elseif (! $plan->isFree() && ! $tenant->hasStripeSubscription())
                    @if (! $canBill)
                        <p class="mt-6 rounded-2xl bg-slate-50 p-3 text-center text-sm text-slate-500 dark:bg-slate-800">الترقية متاحة لمالك المتجر فقط.</p>
                    @elseif ($online)
                        <form method="POST" action="{{ route('tenant.billing.checkout') }}" class="mt-6">
                            @csrf
                            <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                            <input type="hidden" name="interval" :value="{{ $yearly ? 'interval' : "'month'" }}" value="month">
                            <button type="submit" class="dd-btn w-full bg-linear-to-l from-amber-500 to-orange-500 text-white shadow-lg shadow-orange-500/20">
                                <flux:icon.credit-card variant="micro" /> اشترك الآن بالبطاقة
                            </button>
                        </form>
                        <p class="mt-2 flex items-center justify-center gap-1 text-center text-xs text-slate-400"><flux:icon.lock-closed variant="micro" /> دفع آمن عبر Stripe — يمكنك الإلغاء في أي وقت.</p>
                    @else
                        <a href="mailto:{{ config('mail.from.address') }}?subject={{ rawurlencode('طلب ترقية: '.$tenant->name.' (#'.$tenant->id.')') }}" class="dd-btn mt-6 w-full bg-linear-to-l from-amber-500 to-orange-500 text-white shadow-lg shadow-orange-500/20">اطلب الترقية الآن</a>
                        <p class="mt-2 text-center text-xs text-slate-400">يتم تفعيل الاشتراك من إدارة المنصة بعد تأكيد الدفع.</p>
                    @endif
                @endif
            </section>
        @endforeach

        {{-- Billing history --}}
        @if ($canBill && $payments->isNotEmpty())
            <section class="dd-card p-6">
                <h2 class="font-bold">سجل الدفعات</h2>
                <div class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($payments as $payment)
                        <div class="flex items-center gap-3 py-3 text-sm">
                            <span @class(['dd-chip px-2.5 py-1',
                                'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $payment->status === 'paid',
                                'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $payment->status !== 'paid'])>{{ $payment->status === 'paid' ? 'مدفوعة' : 'فشلت' }}</span>
                            <span class="flex-1 text-slate-500">{{ $payment->created_at->format('Y/m/d') }}</span>
                            <span class="font-bold tabular-nums" dir="ltr">{{ $payment->formattedAmount() }}</span>
                            @if ($payment->hosted_invoice_url)
                                <a href="{{ $payment->hosted_invoice_url }}" target="_blank" rel="noopener" class="text-xs font-medium text-indigo-600">الفاتورة</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</div>
