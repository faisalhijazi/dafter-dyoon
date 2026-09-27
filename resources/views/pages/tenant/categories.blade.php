<?php

use App\Models\Account;
use App\Models\Category;
use App\Support\Palette;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('إدارة الأقسام')] class extends Component {
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $color = 'violet';

    #[Computed]
    public function categories()
    {
        return Category::withCount('accounts')->orderBy('sort_order')->get();
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'name');
        $this->color = collect(Category::COLORS)->diff($this->categories->pluck('color'))->first() ?? 'violet';
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $category = Category::findOrFail($id);
        $this->resetValidation();
        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->color = $category->color;
        $this->showModal = true;
    }

    public function save(): void
    {
        $tenantId = auth()->user()->tenant_id;

        $this->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('categories')->where('tenant_id', $tenantId)->ignore($this->editingId)],
            'color' => ['required', Rule::in(Category::COLORS)],
        ], attributes: ['name' => 'اسم القسم']);

        if ($this->editingId) {
            Category::findOrFail($this->editingId)->update(['name' => $this->name, 'color' => $this->color]);
        } else {
            Category::create(['name' => $this->name, 'color' => $this->color, 'sort_order' => ($this->categories->max('sort_order') ?? 0) + 1]);
        }

        $this->showModal = false;
        unset($this->categories);
        $this->dispatch('toast', message: 'تم حفظ القسم.');
    }

    public function delete(): void
    {
        $category = Category::findOrFail($this->editingId);

        if ($category->is_default) {
            return;
        }

        // Keep the accounts: move them to the first default category.
        $fallback = Category::where('is_default', true)->orderBy('sort_order')->first();
        Account::where('category_id', $category->id)->update(['category_id' => $fallback?->id]);
        $category->delete();

        $this->showModal = false;
        unset($this->categories);
        $this->dispatch('toast', message: 'تم حذف القسم ونقل حساباته إلى «'.$fallback?->name.'».');
    }

    /** Called by wire:sort with the dragged category id and its new index. */
    public function reorder(int $id, int $position): void
    {
        $ids = $this->categories->pluck('id')->reject(fn ($v) => $v === $id)->values()->all();
        array_splice($ids, $position, 0, [$id]);

        DB::transaction(function () use ($ids) {
            foreach ($ids as $index => $categoryId) {
                Category::whereKey($categoryId)->update(['sort_order' => $index + 1]);
            }
        });

        unset($this->categories);
    }
}; ?>

<div class="pb-36">
    <x-dd.hero title="إدارة الأقسام" :back="route('tenant.settings')" icon="square-3-stack-3d" />

    <div class="relative -mt-4 space-y-5 px-5">
        <section class="dd-card p-6">
            <div class="grid grid-cols-2 gap-4">
                <div class="flex items-center gap-4">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-500 dark:bg-indigo-500/10"><flux:icon.square-3-stack-3d variant="solid" /></span>
                    <div><p class="text-slate-500">إجمالي الأقسام</p><p class="text-2xl font-bold">{{ $this->categories->count() }}</p></div>
                </div>
                <div class="flex items-center gap-4">
                    <span class="flex size-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-500 dark:bg-emerald-500/10"><flux:icon.users variant="solid" /></span>
                    <div><p class="text-slate-500">الحسابات المندرجة</p><p class="text-2xl font-bold">{{ $this->categories->sum('accounts_count') }}</p></div>
                </div>
            </div>
            <p class="mt-5 flex items-start gap-3 rounded-2xl bg-slate-100 p-4 text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                <flux:icon.information-circle class="mt-0.5 shrink-0" />
                يمكنك سحب القسم من المقبض لإعادة ترتيبه، أو الضغط عليه لتعديله.
            </p>
        </section>

        <ul wire:sort="reorder" class="space-y-4">
            @foreach ($this->categories as $category)
                <li wire:key="cat-{{ $category->id }}" wire:sort:item="{{ $category->id }}" class="flex items-center gap-4 rounded-[2rem] border-2 border-slate-300 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
                    <span wire:sort:handle class="cursor-grab text-slate-300 active:cursor-grabbing" aria-label="اسحب لإعادة الترتيب">
                        <svg class="size-6" viewBox="0 0 24 24" fill="currentColor"><circle cx="8" cy="5" r="2"/><circle cx="16" cy="5" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="8" cy="19" r="2"/><circle cx="16" cy="19" r="2"/></svg>
                    </span>
                    <button type="button" wire:click="edit({{ $category->id }})" class="flex flex-1 items-center gap-4 text-start">
                        <span @class(['flex size-20 shrink-0 items-center justify-center rounded-3xl shadow-xl', $category->is_default ? 'bg-linear-to-br from-slate-900 to-blue-500 text-white' : Palette::tile($category->color)])>
                            <flux:icon :name="$category->is_default ? 'star' : 'folder'" variant="solid" class="size-8" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="text-2xl font-bold">{{ $category->name }}</span>
                                @if ($category->is_default)
                                    <span class="rounded-full bg-linear-to-l from-slate-900 to-blue-500 px-3 py-0.5 text-xs text-white">الافتراضي</span>
                                @endif
                            </span>
                            <span class="mt-2 flex items-center gap-2 text-slate-500">
                                <span class="flex size-9 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800"><flux:icon.user-group variant="micro" /></span>
                                عدد الحسابات: {{ $category->accounts_count }}
                            </span>
                        </span>
                    </button>
                </li>
            @endforeach
        </ul>
    </div>

    <button type="button" wire:click="create" class="dd-fab">
        <flux:icon.plus class="size-7" /> إضافة قسم
    </button>

    {{-- Add / edit dialog --}}
    <div x-data="{ open: $wire.entangle('showModal') }" x-cloak x-show="open" x-on:keydown.escape.window="open = false" class="fixed inset-0 z-50 flex items-center justify-center p-6">
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-slate-900/50" x-on:click="open = false"></div>
        <form x-show="open" x-transition.scale.95 wire:submit="save" class="relative w-full max-w-md rounded-[2rem] bg-white p-7 shadow-2xl dark:bg-slate-900">
            <div class="flex items-center gap-4 border-b border-slate-200 pb-5 dark:border-slate-700">
                <span class="flex size-16 items-center justify-center rounded-2xl {{ Palette::tile('dark') }}"><flux:icon.square-3-stack-3d variant="solid" class="size-8" /></span>
                <h2 class="text-2xl font-bold">{{ $editingId ? 'تعديل القسم' : 'إضافة قسم جديد' }}</h2>
            </div>

            <div class="relative mt-7">
                <label for="cat-name" class="absolute -top-3 right-5 bg-white px-2 text-sm text-slate-600 dark:bg-slate-900">اسم القسم *</label>
                <div class="flex items-center gap-3 rounded-2xl border-2 border-slate-900 p-2 dark:border-slate-500">
                    <span class="flex size-12 items-center justify-center rounded-xl bg-slate-100 dark:bg-slate-800"><flux:icon.tag variant="solid" class="size-5" /></span>
                    <input id="cat-name" type="text" wire:model="name" x-effect="open && $nextTick(() => $el.focus())" placeholder="دخل اسم القسم" class="flex-1 bg-transparent text-lg focus:outline-none">
                </div>
                @error('name') <p class="dd-error">{{ $message }}</p> @enderror
            </div>

            <div class="mt-5">
                <p class="dd-label">اللون</p>
                <div class="flex flex-wrap gap-2">
                    @foreach (Palette::DOT as $key => $dot)
                        <button type="button" wire:click="$set('color', '{{ $key }}')" @class(['size-9 rounded-full ring-offset-2 transition dark:ring-offset-slate-900', $dot, 'ring-2 ring-slate-900 dark:ring-white' => $color === $key]) aria-label="{{ $key }}"></button>
                    @endforeach
                </div>
            </div>

            <button type="submit" class="dd-btn-primary mt-7 w-full py-4 text-lg"><flux:icon.plus variant="mini" /> {{ $editingId ? 'حفظ التعديلات' : 'إضافة القسم' }}</button>
            @if ($editingId && ! $this->categories->firstWhere('id', $editingId)?->is_default)
                <button type="button" wire:click="delete" wire:confirm="حذف هذا القسم؟ سيتم نقل حساباته إلى القسم الافتراضي." class="dd-btn mt-3 w-full border border-rose-200 text-rose-600">حذف القسم</button>
            @endif
            <button type="button" x-on:click="open = false" class="dd-btn mt-3 w-full border border-slate-100 text-slate-500 dark:border-slate-800">إلغاء</button>
        </form>
    </div>
</div>
