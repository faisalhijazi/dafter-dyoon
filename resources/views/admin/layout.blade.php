<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', 'لوحة الإدارة') — {{ config('app.name') }}</title>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @include('partials.favicons')
    </head>
    <body class="min-h-screen bg-[#F8FAFC] text-slate-900 antialiased">
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <header class="mb-8 rounded-[28px] bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 p-6 text-white shadow-lg shadow-indigo-950/20">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-5">
                        <a href="{{ route('admin.dashboard') }}" class="border-l border-white/15 pl-5"><x-brand.logo tone="light" class="h-12" /></a>
                        <div>
                            <div class="text-sm text-indigo-200">لوحة المشرف العام</div>
                        <h1 class="mt-1 text-3xl font-black">@yield('heading', 'لوحة الإدارة')</h1>
                    </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="hidden text-sm text-indigo-100 sm:inline">{{ auth('admin')->user()->name }}</span>
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button type="submit" class="rounded-full bg-white/10 px-4 py-2 text-sm font-medium text-white ring-1 ring-white/10 transition hover:bg-white/15">تسجيل الخروج</button>
                        </form>
                    </div>
                </div>
                <nav class="mt-6 flex flex-wrap gap-2 text-sm">
                    @foreach ([
                        'admin.dashboard' => ['الرئيسية', 'admin.dashboard'],
                        'admin.tenants.index' => ['المشتركون', 'admin.tenants.*'],
                        'admin.plans.index' => ['الخطط', 'admin.plans.*'],
                        'admin.assistant.index' => ['تعلّم المساعد', 'admin.assistant.*'],
                    ] as $route => [$label, $pattern])
                        <a href="{{ route($route) }}" @class([
                            'rounded-full px-4 py-2 font-medium transition',
                            'bg-white text-slate-900' => request()->routeIs($pattern),
                            'bg-white/5 text-indigo-100 hover:bg-white/10' => ! request()->routeIs($pattern),
                        ])>{{ $label }}</a>
                    @endforeach
                </nav>
            </header>

            @if (session('success'))
                <div class="mb-6 rounded-2xl bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">{{ session('success') }}</div>
            @endif
            @if ($errors->any())
                <div class="mb-6 rounded-2xl bg-rose-50 px-5 py-4 text-sm text-rose-700 ring-1 ring-rose-200">
                    <ul class="list-inside list-disc space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </div>
    </body>
</html>
