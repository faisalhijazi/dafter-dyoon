@extends('statement.layout')

@section('title', 'كشف حساب '.$account->name)

@php
    // Written to the customer: a positive balance (credit / له) is "لكم", negative is "عليكم".
    $status = fn (float $b) => round($b, 2) == 0.0 ? 'متزن' : ($b > 0 ? 'لكم' : 'عليكم');
    $input = 'w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none transition focus:border-indigo-400 focus:bg-white focus:ring-4 focus:ring-indigo-100';
@endphp

@section('content')
    <header class="rounded-[28px] bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-800 px-6 py-6 text-white shadow-lg shadow-indigo-950/20">
        <div class="flex items-center gap-3">
            <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-white/10 text-xl font-black">{{ \Illuminate\Support\Str::substr($tenant->name, 0, 1) }}</div>
            <div class="min-w-0">
                <div class="truncate text-lg font-black">{{ $tenant->name }}</div>
                <div class="text-xs text-indigo-100">كشف حساب إلكتروني حي</div>
            </div>
        </div>
        <h1 class="mt-6 text-3xl font-black">{{ $account->name }}</h1>
        <p class="mt-1 text-sm text-indigo-200">محدَّث لحظة فتح الصفحة: {{ now()->format('Y/m/d h:i A') }}</p>
    </header>

    @if (session('success'))
        <div class="mt-5 rounded-2xl bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">✓ {{ session('success') }}</div>
    @endif

    {{-- Balances --}}
    <section class="mt-5 grid gap-3 sm:grid-cols-2">
        @forelse ($balances as $row)
            @php($b = $row['balance'])
            <div @class(['rounded-[28px] p-5 ring-1', 'bg-emerald-50 ring-emerald-200' => $b > 0, 'bg-rose-50 ring-rose-200' => $b < 0])>
                <div @class(['text-sm font-medium', 'text-emerald-700' => $b > 0, 'text-rose-700' => $b < 0])>الرصيد بـ{{ $row['currency']->name ?? $row['currency']->code }}</div>
                <div @class(['mt-2 text-3xl font-black tabular-nums', 'text-emerald-600' => $b > 0, 'text-rose-600' => $b < 0])>
                    {{ $row['currency']->format(abs($b)) }} <span class="text-lg">{{ $row['currency']->code }}</span>
                </div>
                <div @class(['mt-1 text-sm font-bold', 'text-emerald-700' => $b > 0, 'text-rose-700' => $b < 0])>{{ $status($b) }}</div>
            </div>
        @empty
            <div class="rounded-[28px] bg-white p-5 text-center ring-1 ring-slate-200 sm:col-span-2">
                <div class="text-2xl font-black">⚪ الحساب متزن</div>
                <p class="mt-1 text-slate-500">لا يوجد رصيد مستحق لكم أو عليكم.</p>
            </div>
        @endforelse

        @if ($balances->count() > 1 && $base)
            <div class="rounded-[28px] bg-white p-5 ring-1 ring-slate-200 sm:col-span-2">
                <div class="text-sm text-slate-500">الإجمالي محوَّلاً إلى {{ $base->code }} (حسب أسعار الصرف الحالية)</div>
                <div class="mt-1 text-2xl font-black tabular-nums">{{ $base->format(abs($total)) }} {{ $base->code }} <span class="text-base">{{ $status($total) }}</span></div>
            </div>
        @endif
    </section>

    {{-- Movements --}}
    <section class="mt-6 rounded-[32px] bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-6">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2 px-1">
            <h2 class="text-xl font-black">الحركات</h2>
            <span class="text-sm text-slate-500">{{ $transactions->count() + $hiddenCount }} حركة</span>
        </div>

        @if ($unconfirmed)
            <form method="POST" action="{{ route('statement.confirm', $account->statement_token) }}" class="mb-4 flex flex-wrap items-center gap-3 rounded-2xl bg-emerald-50 p-4 ring-1 ring-emerald-200">
                @csrf
                <p class="flex-1 text-sm text-emerald-800">هل الحركات صحيحة؟ تأكيدك يحفظ حقك وحق المتجر.</p>
                <button type="submit" class="rounded-full bg-emerald-600 px-5 py-2 text-sm font-bold text-white">✓ أؤكد صحة الكشف</button>
            </form>
        @endif

        <div class="divide-y divide-slate-100">
            @forelse ($transactions as $tx)
                @php($credit = $tx->isCredit())
                <div id="tx-{{ $tx->id }}" @class(['flex items-start gap-3 px-1 py-4', 'rounded-2xl bg-amber-50 px-3' => $disputeTx?->id === $tx->id])>
                    <span @class(['mt-1 flex size-10 shrink-0 items-center justify-center rounded-2xl text-lg font-black text-white', 'bg-emerald-500' => $credit, 'bg-rose-500' => ! $credit])>{{ $credit ? '↑' : '↓' }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                            <span @class(['text-lg font-black tabular-nums', 'text-emerald-600' => $credit, 'text-rose-600' => ! $credit])>
                                {{ $tx->currency->format($tx->amount) }} {{ $tx->currency->code }} <span class="text-sm">{{ $credit ? 'لكم' : 'عليكم' }}</span>
                            </span>
                            <span class="text-xs text-slate-400" dir="ltr">{{ $tx->occurred_at->format('Y/m/d h:i A') }}</span>
                        </div>
                        @if (filled($tx->notes))
                            <p class="mt-1 text-sm text-slate-600">{{ $tx->notes }}</p>
                        @endif
                        <div class="mt-1 flex items-center justify-between gap-3 text-xs text-slate-400">
                            <span>الرصيد بعدها: <b class="tabular-nums text-slate-600">{{ $tx->currency->format(abs($tx->running_balance)) }} {{ $tx->currency->code }} {{ $status($tx->running_balance) }}</b></span>
                            <span class="flex shrink-0 items-center gap-1.5">
                                @if ($tx->confirmed_at)
                                    <span class="rounded-full bg-emerald-50 px-3 py-1 font-medium text-emerald-700">✓ مؤكدة</span>
                                @else
                                    <form method="POST" action="{{ route('statement.confirm', $account->statement_token) }}">
                                        @csrf
                                        <input type="hidden" name="transaction_id" value="{{ $tx->id }}">
                                        <button type="submit" class="rounded-full bg-emerald-50 px-3 py-1 font-medium text-emerald-700 hover:bg-emerald-100">أؤكد</button>
                                    </form>
                                @endif
                                <a href="{{ route('statement.show', [$account->statement_token, 'tx' => $tx->id]) }}#dispute" class="rounded-full bg-slate-100 px-3 py-1 font-medium text-slate-600 hover:bg-amber-100 hover:text-amber-800">اعتراض</a>
                            </span>
                        </div>
                    </div>
                </div>
            @empty
                <p class="py-10 text-center text-slate-500">لا توجد حركات مسجلة بعد.</p>
            @endforelse
        </div>

        @if ($hiddenCount)
            <p class="mt-3 rounded-2xl bg-slate-50 px-4 py-3 text-center text-sm text-slate-500">تُعرض آخر {{ $transactions->count() }} حركة فقط. للحركات الأقدم تواصل مع {{ $tenant->name }}.</p>
        @endif
    </section>

    {{-- Report a payment --}}
    <section id="payment" class="mt-6 scroll-mt-6 rounded-[32px] bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-xl font-black">دفعت؟ أبلغنا</h2>
        <p class="mt-1 text-sm text-slate-500">إذا حوّلت مبلغاً أو دفعته بطريقة أخرى، أرسل التفاصيل وصورة الإيصال. يُضاف لكشفك بعد أن يؤكده {{ $tenant->name }}.</p>

        <form method="POST" action="{{ route('statement.payment', $account->statement_token) }}" enctype="multipart/form-data" class="mt-5 space-y-4">
            @csrf
            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label for="pay-amount" class="mb-2 block text-sm font-medium text-slate-600">المبلغ</label>
                    <input id="pay-amount" name="amount" type="number" step="0.01" min="0.01" required value="{{ old('amount') }}" dir="ltr" class="{{ $input }} text-left">
                </div>
                <div>
                    <label for="pay-currency" class="mb-2 block text-sm font-medium text-slate-600">العملة</label>
                    <select id="pay-currency" name="currency_id" class="{{ $input }}">
                        @foreach ($payCurrencies as $cur)
                            <option value="{{ $cur->id }}" @selected((int) old('currency_id') === $cur->id)>{{ $cur->code }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label for="pay-method" class="mb-2 block text-sm font-medium text-slate-600">طريقة الدفع</label>
                    <select id="pay-method" name="method" class="{{ $input }}">
                        @foreach ($methods as $key => $label)
                            <option value="{{ $key }}" @selected(old('method') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="pay-ref" class="mb-2 block text-sm font-medium text-slate-600">رقم الحوالة / المرجع <span class="text-slate-400">— اختياري</span></label>
                    <input id="pay-ref" name="reference" value="{{ old('reference') }}" maxlength="120" dir="ltr" class="{{ $input }} text-left">
                </div>
            </div>
            <div>
                <label for="pay-name" class="mb-2 block text-sm font-medium text-slate-600">اسم الدافع <span class="text-slate-400">— اختياري</span></label>
                <input id="pay-name" name="payer_name" value="{{ old('payer_name') }}" maxlength="120" class="{{ $input }}">
            </div>
            <div>
                <label for="pay-receipt" class="mb-2 block text-sm font-medium text-slate-600">صورة الإيصال <span class="text-slate-400">— اختياري</span></label>
                <input id="pay-receipt" name="receipt" type="file" accept="image/*" class="block w-full text-sm text-slate-500 file:ml-3 file:rounded-full file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:font-bold">
            </div>
            <textarea name="notes" rows="2" maxlength="500" placeholder="ملاحظة (اختياري)" class="{{ $input }}">{{ old('notes') }}</textarea>
            <button type="submit" class="w-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 px-5 py-3 font-bold text-white shadow-lg shadow-emerald-500/25">إرسال بلاغ الدفعة</button>
        </form>
    </section>

    {{-- Dispute --}}
    <section id="dispute" class="mt-6 scroll-mt-6 rounded-[32px] bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <h2 class="text-xl font-black">هل لديك اعتراض؟</h2>
        <p class="mt-1 text-sm text-slate-500">إن وجدت حركة غير صحيحة أو ناقصة، أرسل ملاحظتك مباشرة إلى {{ $tenant->name }}.</p>

        @if ($errors->any())
            <div class="mt-4 rounded-2xl bg-rose-50 px-4 py-3 text-sm text-rose-700 ring-1 ring-rose-200">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('statement.dispute', $account->statement_token) }}" class="mt-5 space-y-4">
            @csrf

            <div>
                <label for="transaction_id" class="mb-2 block text-sm font-medium text-slate-600">الحركة المعنيّة</label>
                <select id="transaction_id" name="transaction_id" class="{{ $input }}">
                    <option value="">الكشف بشكل عام</option>
                    @foreach ($transactions as $tx)
                        <option value="{{ $tx->id }}" @selected((int) old('transaction_id', $disputeTx?->id) === $tx->id)>
                            {{ $tx->occurred_at->format('Y/m/d') }} — {{ $tx->currency->format($tx->amount) }} {{ $tx->currency->code }} {{ $tx->isCredit() ? 'لكم' : 'عليكم' }}{{ filled($tx->notes) ? ' — '.\Illuminate\Support\Str::limit($tx->notes, 30) : '' }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="name" class="mb-2 block text-sm font-medium text-slate-600">اسمك <span class="text-slate-400">— اختياري</span></label>
                <input id="name" name="name" value="{{ old('name') }}" maxlength="120" class="{{ $input }}">
            </div>

            <div>
                <label for="message" class="mb-2 block text-sm font-medium text-slate-600">تفاصيل الاعتراض</label>
                <textarea id="message" name="message" rows="4" required minlength="3" maxlength="1000" placeholder="مثال: المبلغ الصحيح 150 وليس 200" class="{{ $input }}">{{ old('message') }}</textarea>
            </div>

            <button type="submit" class="w-full rounded-full bg-gradient-to-r from-amber-500 to-orange-500 px-5 py-3 font-bold text-white shadow-lg shadow-orange-500/25">إرسال الاعتراض</button>
        </form>
    </section>
@endsection
