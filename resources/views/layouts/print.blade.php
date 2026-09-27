@props(['title', 'subtitle' => null, 'back' => null])

{{-- A4 printable page: the browser's "print / save as PDF" produces the PDF. --}}
<!DOCTYPE html>
<html lang="ar" dir="rtl">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $title }} — {{ auth()->user()->tenant->name }}</title>
        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @include('partials.favicons')
        <style>
            @page { size: A4; margin: 12mm; }
            @media print {
                html, body { background: #fff !important; }
                .sheet { box-shadow: none !important; margin: 0 !important; padding: 0 !important; max-width: none !important; }
                table { page-break-inside: auto; }
                tr { page-break-inside: avoid; }
                thead { display: table-header-group; }
            }
        </style>
    </head>
    <body class="bg-slate-100 font-sans text-slate-900 antialiased" @if (request()->boolean('print')) onload="window.print()" @endif>
        <div class="no-print sticky top-0 z-10 flex items-center gap-3 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur">
            @if ($back)
                <a href="{{ $back }}" class="rounded-full bg-slate-100 px-4 py-2 text-sm font-bold text-slate-600">→ رجوع</a>
            @endif
            <span class="flex-1 truncate text-sm font-bold text-slate-500">{{ $title }}</span>
            {{ $toolbar ?? '' }}
            <button type="button" onclick="window.print()" class="rounded-full bg-slate-900 px-5 py-2 text-sm font-bold text-white">🖨️ طباعة / حفظ PDF</button>
        </div>

        <main class="sheet mx-auto my-6 max-w-[210mm] bg-white p-10 shadow-xl print:my-0">
            <header class="flex items-start justify-between gap-6 border-b-2 border-slate-900 pb-5">
                <div>
                    <h1 class="text-2xl font-black">{{ $title }}</h1>
                    @if ($subtitle)
                        <p class="mt-1 text-sm text-slate-500">{{ $subtitle }}</p>
                    @endif
                    <p class="mt-3 text-sm"><b>{{ auth()->user()->tenant->name }}</b></p>
                    <p class="text-xs text-slate-400">تاريخ الإصدار: {{ now()->format('Y/m/d h:i A') }}</p>
                </div>
                <x-brand.logo tone="dark" class="h-14" />
            </header>

            <div class="mt-6">
                {{ $slot }}
            </div>

            <footer class="mt-10 border-t border-slate-200 pt-3 text-center text-[11px] text-slate-400">
                صادر من {{ config('app.name') }} — {{ auth()->user()->tenant->name }}
            </footer>
        </main>
    </body>
</html>
