<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>تسجيل الدخول للإدارة</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-[#F8FAFC] text-slate-900 antialiased">
        <div class="mx-auto flex min-h-screen max-w-6xl items-center justify-center px-4 py-10">
            <div class="grid w-full max-w-5xl overflow-hidden rounded-[32px] bg-white shadow-[0_20px_70px_rgba(15,23,42,0.08)] ring-1 ring-slate-200 lg:grid-cols-2">
                <div class="hidden bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-800 p-10 text-white lg:flex lg:flex-col lg:justify-between">
                    <div>
                        <div class="mb-8 inline-flex rounded-full border border-white/20 bg-white/5 px-3 py-1 text-xs font-medium text-slate-200">حلول</div>
                        <h1 class="text-4xl font-black leading-tight">لوحة المدير</h1>
                        <p class="mt-4 max-w-md text-sm text-slate-300">متابعة الشركات المشتركة، خطط الاشتراكات، وأداء المنصة في مكان واحد.</p>
                    </div>
                    <div class="rounded-3xl border border-white/10 bg-white/5 p-5 text-sm text-slate-200">
                        <div class="mb-2 font-semibold text-white">إحصائيات اليوم</div>
                        <div class="flex items-center justify-between"><span>الشركات النشطة</span><span class="font-bold text-emerald-300">128</span></div>
                        <div class="mt-2 flex items-center justify-between"><span>المعاملات</span><span class="font-bold text-sky-300">8,420</span></div>
                    </div>
                </div>

                <div class="p-6 sm:p-10">
                    <div class="mb-8 text-right">
                        <div class="text-sm font-medium text-slate-500">إدارة عالية</div>
                        <h2 class="mt-2 text-3xl font-black text-slate-900">تسجيل الدخول</h2>
                    </div>

                    <form method="POST" action="{{ route('admin.login.submit') }}" class="space-y-5">
                        @csrf

                        <div>
                            <label for="email" class="mb-2 block text-sm font-medium text-slate-700">البريد الإلكتروني</label>
                            <input id="email" name="email" type="email" required value="{{ old('email') }}" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-right outline-none transition focus:border-indigo-500 focus:bg-white" placeholder="admin@debtbook.app">
                            @error('email')
                                <span class="mt-2 block text-sm text-red-500">{{ $message }}</span>
                            @enderror
                        </div>

                        <div>
                            <label for="password" class="mb-2 block text-sm font-medium text-slate-700">كلمة المرور</label>
                            <input id="password" name="password" type="password" required class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-right outline-none transition focus:border-indigo-500 focus:bg-white" placeholder="********">
                        </div>

                        <div class="flex items-center justify-between gap-4 text-sm">
                            <label class="inline-flex items-center gap-2 text-slate-600">
                                <input type="checkbox" name="remember" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                تذكرني
                            </label>
                            <a href="#" class="text-indigo-600 hover:underline">هل نسيت كلمة المرور؟</a>
                        </div>

                        <button type="submit" class="w-full rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-900 to-indigo-700 px-4 py-3 text-base font-bold text-white shadow-lg shadow-indigo-500/20 transition hover:opacity-95">دخول لوحة الإدارة</button>
                    </form>
                </div>
            </div>
        </div>
    </body>
</html>
