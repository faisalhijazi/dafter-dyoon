{{-- Light / dark / system switch backed by Flux's persisted appearance --}}
<div x-data class="grid grid-cols-3 gap-1 rounded-3xl bg-white p-2 dark:bg-slate-950" role="radiogroup" aria-label="مظهر التطبيق">
    @foreach (['light' => ['فاتح', 'sun'], 'dark' => ['داكن', 'moon'], 'system' => ['تلقائي', 'computer-desktop']] as $value => [$label, $icon])
        <button type="button" role="radio"
                x-on:click="$flux.appearance = '{{ $value }}'"
                x-bind:aria-checked="$flux.appearance === '{{ $value }}'"
                x-bind:class="$flux.appearance === '{{ $value }}'
                    ? 'bg-slate-900 text-white shadow-lg dark:bg-white dark:text-slate-900'
                    : 'text-slate-500 hover:bg-slate-50 dark:hover:bg-slate-800'"
                class="flex flex-col items-center gap-1.5 rounded-2xl py-3 text-sm font-medium transition">
            <flux:icon :name="$icon" variant="solid" class="size-6" />
            {{ $label }}
        </button>
    @endforeach
</div>
