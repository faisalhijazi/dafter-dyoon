@props(['title' => null])

<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>{{ filled($title) ? $title.' - '.config('app.name') : config('app.name') }}</title>

        @include('partials.favicons')

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-[#F8FAFC] text-slate-900 antialiased">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <header class="mb-10 flex items-center justify-between rounded-[28px] bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-800 px-6 py-4 text-white shadow-lg shadow-indigo-950/20">
                <a href="{{ route('home') }}" class="flex items-center gap-3">
                    <x-brand.logo tone="light" class="h-11 sm:h-12" />
                </a>
                <div class="flex items-center gap-3">
                    <a href="{{ route('home') }}" class="hidden text-sm text-slate-200 transition hover:text-white sm:inline">الرئيسية</a>
                    @if (request()->routeIs('register'))
                        <a href="{{ route('login') }}" class="rounded-full bg-white px-4 py-2 text-sm font-bold text-slate-900 shadow-sm">تسجيل الدخول</a>
                    @elseif (Route::has('register'))
                        <a href="{{ route('register') }}" class="rounded-full bg-white px-4 py-2 text-sm font-bold text-slate-900 shadow-sm">إنشاء حساب</a>
                    @endif
                </div>
            </header>

            <main class="grid items-center gap-8 lg:grid-cols-[0.95fr_1.05fr]">
                <div class="mx-auto w-full max-w-md rounded-[32px] bg-white p-7 shadow-[0_25px_80px_rgba(15,23,42,0.08)] ring-1 ring-slate-200 sm:p-8">
                    {{ $slot }}
                </div>

                <aside class="hidden lg:block">
                    <div class="rounded-[32px] bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-800 p-8 text-white shadow-xl shadow-indigo-950/20">
                        <div class="inline-flex rounded-full bg-emerald-400/15 px-3 py-1 text-sm font-medium text-emerald-300 ring-1 ring-emerald-400/20">سحابي • سريع • آمن</div>
                        <h2 class="mt-5 text-3xl font-black leading-tight">كل ديونك وحساباتك في دفتر واحد</h2>
                        <p class="mt-3 text-indigo-100">تتبع كل عميل ومورد وكل دفعة ورصيد، وشارك المعاملات عبر الواتساب في ثوانٍ.</p>

                        <div class="mt-8 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-2xl bg-emerald-500/15 p-4 ring-1 ring-emerald-400/20">
                                <div class="text-xs text-emerald-200">لك</div>
                                <div class="mt-2 text-2xl font-black text-emerald-300">12,450</div>
                            </div>
                            <div class="rounded-2xl bg-red-500/10 p-4 ring-1 ring-red-400/20">
                                <div class="text-xs text-red-200">عليك</div>
                                <div class="mt-2 text-2xl font-black text-red-300">3,200</div>
                            </div>
                        </div>

                        <ul class="mt-8 space-y-3 text-sm text-indigo-100">
                            <li class="flex items-center gap-3"><span class="flex size-7 items-center justify-center rounded-full bg-white/10 text-emerald-300">✓</span> حسابات العملاء والموردين بأرصدة لحظية</li>
                            <li class="flex items-center gap-3"><span class="flex size-7 items-center justify-center rounded-full bg-white/10 text-emerald-300">✓</span> عملات متعددة وسقوف تنبيه للديون</li>
                            <li class="flex items-center gap-3"><span class="flex size-7 items-center justify-center rounded-full bg-white/10 text-emerald-300">✓</span> نسخ احتياطي على Google Drive</li>
                        </ul>
                    </div>
                </aside>
            </main>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
