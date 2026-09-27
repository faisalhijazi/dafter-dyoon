@props(['href' => null, 'icon', 'color' => 'violet', 'title', 'subtitle' => null, 'compact' => false, 'chevron' => true, 'navigate' => true])

@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if ($href) href="{{ $href }}" @if ($navigate) wire:navigate @endif @endif
    {{ $attributes->class(['group flex items-center gap-4 transition', $compact ? 'py-3' : 'py-4', 'cursor-pointer' => $href || $attributes->has('wire:click')]) }}>
    <span @class(['flex shrink-0 items-center justify-center rounded-2xl shadow-lg', \App\Support\Palette::tile($color), $compact ? 'size-14' : 'size-16'])>
        <flux:icon :name="$icon" variant="solid" class="size-7" />
    </span>
    <span class="min-w-0 flex-1">
        <span class="block text-lg font-bold text-slate-900 dark:text-white">{{ $title }}</span>
        @if ($subtitle)
            <span class="mt-0.5 block text-sm text-slate-500 dark:text-slate-400">{{ $subtitle }}</span>
        @endif
    </span>
    {{ $slot }}
    @if ($chevron && ($href || $attributes->has('wire:click')))
        <flux:icon.chevron-left variant="mini" class="shrink-0 text-slate-400 transition group-hover:-translate-x-1" />
    @endif
</{{ $tag }}>
