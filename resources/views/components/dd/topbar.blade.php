@props(['title', 'back' => null])

{{-- Light page header: back arrow (start), title, actions (end) --}}
<header {{ $attributes->class('sticky top-0 z-20 flex items-center gap-3 bg-canvas/90 px-5 py-4 backdrop-blur no-print') }}>
    @if ($back)
        <a href="{{ $back }}" wire:navigate class="dd-icon-btn size-11 bg-white text-slate-600 shadow-sm dark:bg-slate-900 dark:text-slate-300" aria-label="رجوع">
            <flux:icon.arrow-right class="size-6" />
        </a>
    @endif
    <h1 class="min-w-0 flex-1 truncate text-2xl font-bold">{{ $title }}</h1>
    {{ $slot }}
</header>
