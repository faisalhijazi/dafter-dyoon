<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
        <title>@yield('title') — {{ config('app.name') }}</title>
        @fonts
        @vite(['resources/css/app.css'])
        @include('partials.favicons')
    </head>
    <body class="min-h-screen bg-[#F8FAFC] text-slate-900 antialiased">
        <div class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
            @yield('content')

            <footer class="mt-10 text-center text-xs text-slate-400">
                <a href="{{ route('home') }}" class="mb-2 inline-block"><x-brand.logo tone="dark" class="h-9 opacity-80" /></a><br>
                كشف حساب إلكتروني عبر <a href="{{ route('home') }}" class="font-bold text-slate-500">{{ config('app.name') }}</a>
            </footer>
        </div>
    </body>
</html>
