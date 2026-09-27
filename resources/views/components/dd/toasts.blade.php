{{-- Toasts: Livewire $this->dispatch('toast', message: '...', type: 'success'|'error') or session flash --}}
<div x-data="{
        items: [],
        push(message, type = 'success') {
            const id = Date.now() + Math.random();
            this.items.push({ id, message, type });
            setTimeout(() => this.items = this.items.filter(i => i.id !== id), 3500);
        },
     }"
     x-init="
        @if (session('success')) push(@js(session('success')), 'success'); @endif
        @if (session('error')) push(@js(session('error')), 'error'); @endif
     "
     x-on:toast.window="push($event.detail.message, $event.detail.type)"
     class="pointer-events-none fixed inset-x-0 top-4 z-[60] flex flex-col items-center gap-2 px-4 no-print">
    <template x-for="item in items" :key="item.id">
        <div x-transition class="pointer-events-auto flex max-w-md items-center gap-3 rounded-2xl px-5 py-3 font-medium text-white shadow-xl"
             :class="item.type === 'error' ? 'bg-rose-600' : 'bg-slate-900 dark:bg-emerald-600'">
            <span x-text="item.type === 'error' ? '⚠️' : '✓'"></span>
            <span x-text="item.message"></span>
        </div>
    </template>
</div>
