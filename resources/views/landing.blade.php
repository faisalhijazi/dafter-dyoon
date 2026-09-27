<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>
        @include('partials.favicons')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="bg-[#F8FAFC] text-slate-900 antialiased">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <header class="mb-10 flex items-center justify-between rounded-[28px] bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-800 px-6 py-4 text-white shadow-lg shadow-indigo-950/20">
                <div class="flex items-center gap-3">
                    <x-brand.logo tone="light" class="h-11 sm:h-12" />
                </div>
                <nav class="hidden items-center gap-6 text-sm text-slate-200 md:flex">
                    <a href="#features" class="transition hover:text-white">المميزات</a>
                    <a href="#pricing" class="transition hover:text-white">الأسعار</a>
                    <a href="#about" class="transition hover:text-white">من نحن</a>
                </nav>
                <div class="flex items-center gap-3">
                    <a href="{{ route('login') }}" class="rounded-full border border-white/15 bg-white/5 px-4 py-2 text-sm font-medium text-white hover:bg-white/10">تسجيل الدخول</a>
                    <a href="{{ route('register') }}" class="rounded-full bg-white px-4 py-2 text-sm font-bold text-slate-900 shadow-sm">إنشاء حساب</a>
                </div>
            </header>

            <main class="space-y-16">
                <section class="grid items-center gap-8 lg:grid-cols-[1.1fr_0.9fr]">
                    <div>
                        <div class="mb-4 inline-flex rounded-full bg-emerald-100 px-3 py-1 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">سحابي • سريع • آمن</div>
                        <h1 class="text-4xl font-black leading-tight text-slate-900 md:text-5xl">مدير ذكي للديون والحسابات لكل متجر وعميل</h1>
                        <p class="mt-5 max-w-xl text-lg text-slate-600">تتبع كل عميل، كل مورد، كل دفعة، وكل رصيد في لوحة واحدة. أدر أعمالك بسهولة، وشارك المعاملات عبر الواتساب في ثوانٍ.</p>
                        <div class="mt-7 flex flex-wrap gap-3">
                            <a href="{{ route('register') }}" class="rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-emerald-500/25">ابدأ مجاناً</a>
                            <a href="{{ route('login') }}" class="rounded-full border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-700 shadow-sm">تسجيل الدخول</a>
                        </div>
                        <div class="mt-8 flex items-center gap-8 text-sm text-slate-500">
                            <div><span class="block text-2xl font-black text-slate-900">4.9/5</span>تقييم المستخدمين</div>
                            <div><span class="block text-2xl font-black text-slate-900">10k+</span>معاملة يومية</div>
                        </div>
                    </div>

                    <div class="rounded-[32px] bg-white p-5 shadow-[0_25px_80px_rgba(15,23,42,0.08)] ring-1 ring-slate-200">
                        <div class="rounded-[26px] bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-800 p-5 text-white">
                            <div class="mb-5 flex items-center justify-between">
                                <div>
                                    <div class="text-sm text-indigo-200">لوحة المتجر</div>
                                    <div class="mt-1 text-xl font-black">ملخص اليوم</div>
                                </div>
                                <button class="rounded-full bg-white/10 px-3 py-1.5 text-xs font-medium text-white">تحديث</button>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="rounded-2xl bg-emerald-500/15 p-4 ring-1 ring-emerald-400/20">
                                    <div class="text-xs text-emerald-200">لك</div>
                                    <div class="mt-2 text-2xl font-black text-emerald-300">12,450</div>
                                </div>
                                <div class="rounded-2xl bg-red-500/10 p-4 ring-1 ring-red-400/20">
                                    <div class="text-xs text-red-200">عليك</div>
                                    <div class="mt-2 text-2xl font-black text-red-300">8,920</div>
                                </div>
                            </div>
                            <div class="mt-5 rounded-2xl bg-white/5 p-4">
                                <div class="mb-2 flex justify-between text-xs text-slate-300">
                                    <span>الرصيد الصافي</span>
                                    <span class="text-emerald-300">+14.5%</span>
                                </div>
                                <div class="text-3xl font-black text-white">3,530 ILS</div>
                            </div>
                        </div>
                        <div class="mt-5 grid gap-3 sm:grid-cols-3">
                            <div class="rounded-2xl bg-slate-50 p-4">
                                <div class="text-xs text-slate-500">الحسابات</div>
                                <div class="mt-2 text-xl font-black text-slate-900">245</div>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-4">
                                <div class="text-xs text-slate-500">المعاملات</div>
                                <div class="mt-2 text-xl font-black text-slate-900">1,280</div>
                            </div>
                            <div class="rounded-2xl bg-slate-50 p-4">
                                <div class="text-xs text-slate-500">العملات</div>
                                <div class="mt-2 text-xl font-black text-slate-900">6</div>
                            </div>
                        </div>
                    </div>
                </section>

                <section id="features" class="space-y-8">
                    <div class="text-center">
                        <div class="text-sm font-medium text-indigo-600">المميزات الأساسية</div>
                        <h2 class="mt-2 text-3xl font-black text-slate-900">كل ما تحتاجه لإدارة الديون بشكل احترافي</h2>
                    </div>
                    <div class="grid gap-5 md:grid-cols-3">
                        <div class="rounded-[28px] bg-white p-6 shadow-sm ring-1 ring-slate-200">
                            <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 text-xl text-emerald-600">💰</div>
                            <h3 class="text-xl font-bold text-slate-900">تتبع فوري للديون</h3>
                            <p class="mt-2 text-slate-600">إتاحة تقارير دقيقة لكل عميل وموّرد مع رصيد مستجد تلقائياً حسب أحدث حركة.</p>
                        </div>
                        <div class="rounded-[28px] bg-white p-6 shadow-sm ring-1 ring-slate-200">
                            <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-100 text-xl text-sky-600">📊</div>
                            <h3 class="text-xl font-bold text-slate-900">تقارير مرنة</h3>
                            <p class="mt-2 text-slate-600">لوحة تحكم متقدمة مع تقارير للأرباح والخسائر، وأرصدة العملاء، والأقسام المخصصة.</p>
                        </div>
                        <div class="rounded-[28px] bg-white p-6 shadow-sm ring-1 ring-slate-200">
                            <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-violet-100 text-xl text-violet-600">🔁</div>
                            <h3 class="text-xl font-bold text-slate-900">تحويل العملات</h3>
                            <p class="mt-2 text-slate-600">هذه المنصة تدعم العملة الأساسية والعملات المحلية مع أسعار الصرف وتحديثها بسهولة.</p>
                        </div>
                    </div>
                </section>

                <section id="pricing" class="py-4">
                    <div class="mb-8 text-center">
                        <div class="text-sm font-medium text-indigo-600">خطط الاشتراك</div>
                        <h2 class="mt-2 text-3xl font-black text-slate-900">اختر الخطة المناسبة</h2>
                    </div>
                    <div class="grid gap-5 lg:grid-cols-2">
                        @forelse ($plans as $plan)
                            @if ($plan->isFree())
                                <div class="rounded-[32px] bg-white p-7 shadow-sm ring-1 ring-slate-200">
                                    <div class="text-sm font-medium text-emerald-600">الخطة {{ $plan->name }}</div>
                                    <h3 class="mt-4 text-3xl font-black text-slate-900">مجاناً</h3>
                                    <p class="mt-2 text-slate-600">{{ $plan->description }}</p>
                                    <ul class="mt-5 space-y-3 text-sm text-slate-600">
                                        @foreach ($plan->features ?? [] as $feature)
                                            <li>• {{ $feature }}</li>
                                        @endforeach
                                    </ul>
                                    <a href="{{ route('register') }}" class="mt-7 block w-full rounded-full border border-slate-200 bg-slate-50 px-4 py-3 text-center text-sm font-bold text-slate-800">ابدأ مجاناً</a>
                                </div>
                            @else
                                <div class="rounded-[32px] bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-800 p-7 text-white shadow-xl shadow-indigo-950/20 ring-1 ring-indigo-700/60">
                                    <div class="text-sm font-medium text-indigo-200">الخطة {{ $plan->name }}</div>
                                    <h3 class="mt-4 text-3xl font-black">
                                        ${{ rtrim(rtrim($plan->price_monthly, '0'), '.') }}
                                        <span class="text-base font-medium text-indigo-200">/ شهرياً</span>
                                    </h3>
                                    @if ((float) $plan->price_yearly > 0)
                                        <div class="mt-1 text-sm text-indigo-200">أو ${{ rtrim(rtrim($plan->price_yearly, '0'), '.') }} سنوياً</div>
                                    @endif
                                    <p class="mt-2 text-indigo-100">{{ $plan->description }}</p>
                                    <ul class="mt-5 space-y-3 text-sm text-indigo-100">
                                        @foreach ($plan->features ?? [] as $feature)
                                            <li>• {{ $feature }}</li>
                                        @endforeach
                                    </ul>
                                    <a href="{{ route('register') }}" class="mt-7 block w-full rounded-full bg-white px-4 py-3 text-center text-sm font-bold text-slate-900">تجربة مجانية</a>
                                </div>
                            @endif
                        @empty
                            <div class="rounded-[32px] bg-white p-7 text-center text-slate-600 shadow-sm ring-1 ring-slate-200 lg:col-span-2">لا توجد خطط متاحة حالياً.</div>
                        @endforelse
                    </div>
                </section>

                <section id="about" class="rounded-[32px] bg-white p-7 shadow-sm ring-1 ring-slate-200">
                    <div class="grid gap-8 md:grid-cols-2">
                        <div>
                            <div class="text-sm font-medium text-indigo-600">لماذا حلول؟</div>
                            <h2 class="mt-2 text-3xl font-black text-slate-900">منصة موحدة لإدارة حساباتك في أي مكان</h2>
                        </div>
                        <div class="text-slate-600">
                            تم تصميم منصة حلول لتناسب المحلات الصغيرة والمتوسطة، مع تجربة بسيطة، أمان عالي، وقابلية توسع لعدد كبير من الشركات والعملاء. كل شيء موحد داخل حساب موثوق وسهل الإدارة.
                        </div>
                    </div>
                </section>
            </main>
        </div>
    </body>
</html>
