<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>دخول الإدارة — {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @include('partials.favicons')
    </head>
    <body class="flex min-h-screen items-center justify-center bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-800 px-4 py-10 text-slate-900 antialiased">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center text-white">
                <x-brand.logo tone="light" class="mx-auto h-16" />
                <h1 class="mt-5 text-3xl font-black">لوحة المشرف العام</h1>
                <p class="mt-2 text-indigo-200">سجّل الدخول لإدارة المنصة</p>
            </div>

            <form method="POST" action="{{ route('admin.login.submit') }}" class="space-y-5 rounded-[32px] bg-white p-7 shadow-2xl">
                @csrf

                <div>
                    <label for="email" class="mb-2 block text-sm font-medium text-slate-600">البريد الإلكتروني</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" dir="ltr"
                           class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-left outline-none transition focus:border-indigo-400 focus:bg-white focus:ring-4 focus:ring-indigo-100">
                    @error('email') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="password" class="mb-2 block text-sm font-medium text-slate-600">كلمة المرور</label>
                    <input id="password" name="password" type="password" required autocomplete="current-password" dir="ltr"
                           class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-left outline-none transition focus:border-indigo-400 focus:bg-white focus:ring-4 focus:ring-indigo-100">
                    @error('password') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" name="remember" value="1" class="size-4 rounded border-slate-300 text-indigo-600">
                    تذكرني
                </label>

                <button type="submit" class="w-full rounded-full bg-gradient-to-r from-indigo-600 to-slate-900 px-5 py-3 font-bold text-white shadow-lg shadow-indigo-600/25 transition hover:opacity-95">تسجيل الدخول</button>
            </form>
        </div>
    </body>
</html>
