<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        @include('partials.head', ['title' => 'الحساب معطّل'])
    </head>
    <body class="flex min-h-screen flex-col items-center justify-center gap-8 bg-canvas p-6 font-sans text-slate-900 dark:text-white">
        <x-brand.logo class="h-14" />
        <div class="dd-card max-w-md p-8 text-center">
            <div class="mx-auto flex size-20 items-center justify-center rounded-full bg-rose-50 text-rose-500 dark:bg-rose-500/10">
                <flux:icon.lock-closed variant="solid" class="size-10" />
            </div>
            <h1 class="mt-6 text-2xl font-extrabold">تم إيقاف حساب «{{ $tenant->name }}»</h1>
            <p class="mt-3 text-slate-500">حسابك موقوف مؤقتاً من إدارة المنصة. بياناتك محفوظة بالكامل، يرجى التواصل مع الدعم لإعادة التفعيل.</p>
            <form method="POST" action="{{ route('logout') }}" class="mt-8">
                @csrf
                <button class="dd-btn-primary w-full">تسجيل الخروج</button>
            </form>
        </div>
        @fluxScripts
    </body>
</html>
