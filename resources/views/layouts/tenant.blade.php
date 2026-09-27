<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        @include('partials.head')
        <meta name="theme-color" content="#0f172a">
    </head>
    <body class="min-h-screen bg-canvas font-sans text-slate-900 antialiased dark:text-slate-100" x-data="{ drawer: false }" x-on:open-drawer.window="drawer = true" x-on:keydown.escape.window="drawer = false">
        <div class="relative mx-auto min-h-screen max-w-3xl bg-canvas">
            {{ $slot }}
        </div>

        @include('tenant.partials.drawer')

        <x-dd.toasts />

        {{-- No connection: point to offline entry (queued entries sync automatically when back online). --}}
        <a x-data="{ online: navigator.onLine }" x-on:online.window="online = true" x-on:offline.window="online = false" x-show="! online" x-cloak
           href="{{ route('tenant.offline') }}" class="fixed inset-x-4 top-4 z-[70] mx-auto flex max-w-md items-center gap-3 rounded-2xl bg-slate-900 px-4 py-3 text-sm font-bold text-white shadow-2xl no-print">
            <flux:icon.signal-slash variant="mini" /> لا يوجد اتصال — سجّل معاملاتك بدون إنترنت
        </a>

        @fluxScripts
        <script>
            document.addEventListener('DOMContentLoaded', () => window.ddOfflineBoot?.(@js(route('tenant.offline.sync')), @js(csrf_token())));
        </script>
    </body>
</html>
