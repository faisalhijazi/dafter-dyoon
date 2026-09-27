@props(['title', 'subtitle' => null, 'back' => null, 'icon' => null])

{{-- Dark gradient page header used by management screens --}}
<header class="relative overflow-hidden bg-linear-to-br from-slate-900 via-slate-900 to-indigo-950 px-6 pb-12 pt-6 text-white">
    <div class="absolute -right-16 -top-16 size-52 rounded-full bg-white/5"></div>
    <div class="absolute -bottom-20 left-4 size-40 rounded-full bg-white/5"></div>
    @if ($icon)
        <flux:icon :name="$icon" variant="solid" class="absolute left-10 top-14 size-24 text-white/5" />
    @endif

    <div class="relative flex min-h-12 items-center justify-between">
        @if ($back)
            <a href="{{ $back }}" wire:navigate class="dd-icon-btn bg-white/15 text-white/80 backdrop-blur hover:bg-white/25" aria-label="رجوع">
                <flux:icon.arrow-right class="size-6" />
            </a>
        @else
            <span></span>
        @endif
        <div class="flex items-center gap-2">{{ $actions ?? '' }}</div>
    </div>

    <h1 class="relative mt-8 text-4xl font-extrabold leading-tight sm:text-5xl">{{ $title }}</h1>
    @if ($subtitle)
        <p class="relative mt-2 text-lg text-white/60">{{ $subtitle }}</p>
    @endif
</header>
