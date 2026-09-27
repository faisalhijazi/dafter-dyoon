{{-- Offline entry: cached by the service worker (public/sw.js) and shown when there is no connection. --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        @include('partials.head', ['title' => 'إدخال بدون إنترنت'])
        <meta name="csrf-token" content="{{ csrf_token() }}">
    </head>
    <body class="min-h-screen bg-canvas font-sans text-slate-900 antialiased dark:text-slate-100">
        <div class="mx-auto max-w-3xl pb-16"
             x-data="ddOffline(@js([
                 'accounts' => $accounts,
                 'currencies' => $currencies,
                 'syncUrl' => route('tenant.offline.sync'),
                 'token' => csrf_token(),
             ]))">

            <header class="flex items-center gap-3 px-5 py-5">
                <a href="{{ route('dashboard') }}" class="dd-icon-btn size-11 bg-white text-slate-600 shadow-sm dark:bg-slate-900" aria-label="الرئيسية"><flux:icon.arrow-right class="size-6" /></a>
                <div class="flex-1">
                    <h1 class="text-2xl font-bold">إدخال بدون إنترنت</h1>
                    <p class="text-xs text-slate-500">قائمة الحسابات محدّثة حتى {{ $snapshotAt->format('Y/m/d h:i A') }}</p>
                </div>
                <span :class="online ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'"
                      class="dd-chip px-3 py-1.5" x-text="online ? '● متصل' : '● غير متصل'"></span>
            </header>

            <div class="space-y-5 px-5">
                <p class="rounded-2xl bg-indigo-50 p-4 text-sm text-indigo-800 dark:bg-indigo-500/10 dark:text-indigo-200">
                    سجّل المعاملات وقت انقطاع الشبكة، وتُحفظ على هذا الجهاز ثم تُرفع تلقائياً عند عودة الاتصال (حتى لو أغلقت الصفحة، تُرفع عند فتح التطبيق).
                </p>

                <section class="dd-card space-y-4 p-5">
                    {{-- Account --}}
                    <template x-if="account">
                        <div class="flex items-center gap-3 rounded-2xl border-2 border-slate-900 p-3 dark:border-slate-500">
                            <span class="flex-1 text-lg font-bold" x-text="account.name"></span>
                            <button type="button" x-on:click="account = null" class="dd-icon-btn size-10 bg-slate-100 dark:bg-slate-800" aria-label="تغيير"><flux:icon.x-mark variant="mini" /></button>
                        </div>
                    </template>
                    <template x-if="! account">
                        <div>
                            <input type="search" x-model="search" placeholder="ابحث عن الشخص…" class="dd-input">
                            <div class="mt-2 max-h-64 divide-y divide-slate-100 overflow-y-auto rounded-2xl bg-white ring-1 ring-slate-100 dark:divide-slate-800 dark:bg-slate-900 dark:ring-slate-800">
                                <template x-for="a in matches" :key="a.id">
                                    <button type="button" x-on:click="account = a" class="block w-full px-4 py-3 text-start hover:bg-slate-50 dark:hover:bg-slate-800" x-text="a.name"></button>
                                </template>
                                <p x-show="! matches.length" class="p-4 text-center text-sm text-slate-400">لا يوجد حساب بهذا الاسم. (إضافة الحسابات تحتاج اتصالاً)</p>
                            </div>
                        </div>
                    </template>

                    {{-- Amount + currency --}}
                    <input type="text" inputmode="decimal" x-model="amount" placeholder="المبلغ" class="dd-input h-16 text-left text-2xl font-bold" dir="ltr">
                    <div class="flex flex-wrap gap-2">
                        <template x-for="c in currencies" :key="c.id">
                            <button type="button" x-on:click="currencyId = c.id" :class="currencyId === c.id ? 'bg-amber-500 text-white' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'" class="dd-chip" x-text="c.code"></button>
                        </template>
                    </div>
                    <input type="text" x-model="notes" maxlength="400" placeholder="ملاحظات (اختياري)" class="dd-input">

                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" x-on:click="record('debit')" class="dd-btn bg-linear-to-br from-red-400 to-red-600 py-4 text-xl text-white">عليه ↓</button>
                        <button type="button" x-on:click="record('credit')" class="dd-btn bg-linear-to-br from-emerald-400 to-emerald-600 py-4 text-xl text-white">له ↑</button>
                    </div>
                    <p x-show="message" x-text="message" class="rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800"></p>
                </section>

                {{-- Queue --}}
                <section class="dd-card p-5">
                    <div class="flex items-center justify-between">
                        <h2 class="font-bold">بانتظار الرفع (<span x-text="queue.length"></span>)</h2>
                        <button type="button" x-show="online && queue.length" x-on:click="sync(true)" :disabled="syncing" class="dd-chip bg-slate-900 text-white disabled:opacity-50 dark:bg-white dark:text-slate-900" x-text="syncing ? 'جاري الرفع…' : 'مزامنة الآن'"></button>
                    </div>
                    <div class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                        <template x-for="e in queue" :key="e.uuid">
                            <div class="flex items-center gap-3 py-2.5 text-sm">
                                <span :class="e.type === 'debit' ? 'text-rose-600' : 'text-emerald-600'" class="font-bold" x-text="(e.type === 'debit' ? 'عليه ' : 'له ') + e.amount + ' ' + (e.currency_code || '')"></span>
                                <span class="flex-1 truncate" x-text="e.account_name"></span>
                                <span x-show="e.error" class="text-xs text-rose-600" x-text="e.error"></span>
                                <button type="button" x-on:click="remove(e.uuid)" class="text-xs text-slate-400 hover:text-rose-600">حذف</button>
                            </div>
                        </template>
                        <p x-show="! queue.length" class="py-4 text-center text-sm text-slate-400">لا توجد معاملات معلّقة ✓</p>
                    </div>
                </section>
            </div>
        </div>

        <x-dd.toasts />
        @livewireScripts
    </body>
</html>
