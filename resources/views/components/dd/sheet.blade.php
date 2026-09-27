@props(['model' => null, 'name' => null, 'title' => null])

{{--
    Bottom sheet. Either bound to a Livewire boolean (model="showForm")
    or opened with $dispatch('open-sheet', { name: '...' }).
--}}
<div x-data="{ open: @if ($model) $wire.entangle('{{ $model }}') @else false @endif }"
     @if ($name)
         x-on:open-sheet.window="if ($event.detail.name === '{{ $name }}') open = true"
         x-on:close-sheet.window="if (! $event.detail || $event.detail.name === '{{ $name }}') open = false"
     @endif
     x-on:keydown.escape.window="open = false"
     class="no-print">
    <div x-cloak x-show="open" class="fixed inset-0 z-50" role="dialog" aria-modal="true">
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-slate-900/50" x-on:click="open = false"></div>

        <div x-show="open"
             x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
             x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
             {{ $attributes->class('absolute inset-x-0 bottom-0 mx-auto max-h-[92vh] max-w-3xl overflow-y-auto rounded-t-[2rem] bg-white pb-8 shadow-2xl dark:bg-slate-900') }}>
            <div class="sticky top-0 z-10 bg-white pt-3 dark:bg-slate-900">
                <div class="mx-auto h-1.5 w-12 rounded-full bg-slate-200 dark:bg-slate-700"></div>
                @if ($title)
                    <h2 class="border-b border-slate-900 py-5 text-center text-xl font-bold dark:border-slate-600">{{ $title }}</h2>
                @endif
            </div>
            <div class="px-5 pt-4">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
