<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Tag;
use App\Support\Palette;
use App\Support\PhoneCodes;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::tenant')] class extends Component {
    public ?Account $account = null;

    public ?int $category_id = null;
    public string $name = '';
    public string $phone_code = '970';
    public string $phone = '';
    public string $address = '';
    public string $notes = '';

    /** @var list<int> */
    public array $tagIds = [];
    public string $newTag = '';
    public string $due_date = '';
    public string $due_repeat = 'none';

    public function mount(?Account $account = null): void
    {
        if ($account?->exists) {
            // Editing an existing account is part of the "delete & edit" staff permission.
            abort_unless(auth()->user()->can('delete-records'), 403);

            $this->account = $account;
            $this->tagIds = $account->tags()->pluck('tags.id')->all();
            $this->due_date = $account->due_date?->format('Y-m-d') ?? '';
            $this->due_repeat = $account->due_repeat ?: 'none';
            $this->fill($account->only(['category_id', 'name']));
            $this->phone_code = $account->phone_code ?: '970';
            $this->phone = (string) $account->phone;
            $this->address = (string) $account->address;
            $this->notes = (string) $account->notes;
        } else {
            $this->category_id = $this->categories->firstWhere('id', (int) request('category'))?->id ?? $this->categories->first()?->id;
        }
    }

    #[Computed]
    public function categories()
    {
        return Category::orderBy('sort_order')->get();
    }

    #[Computed]
    public function allTags()
    {
        return Tag::orderBy('name')->get();
    }

    public function toggleTag(int $id): void
    {
        $this->tagIds = in_array($id, $this->tagIds, true)
            ? array_values(array_diff($this->tagIds, [$id]))
            : [...$this->tagIds, $id];
    }

    public function addTag(): void
    {
        $name = trim(ltrim($this->newTag, '#'));
        $this->validate(['newTag' => ['required', 'string', 'max:40']], attributes: ['newTag' => 'الوسم']);

        $colors = ['sky', 'violet', 'emerald', 'amber', 'pink', 'cyan', 'orange', 'indigo', 'rose'];
        $tag = Tag::firstOrCreate(['name' => $name], ['color' => $colors[Tag::count() % count($colors)]]);

        if (! in_array($tag->id, $this->tagIds, true)) {
            $this->tagIds[] = $tag->id;
        }

        $this->newTag = '';
        unset($this->allTags);
    }

    public function save()
    {
        $tenant = auth()->user()->tenant;

        if (! $this->account && ! $tenant->withinLimit('accounts')) {
            $this->dispatch('toast', message: 'وصلت للحد الأقصى من الحسابات في خطتك الحالية.', type: 'error');

            return $this->redirectRoute('tenant.upgrade', navigate: true);
        }

        $data = $this->validate([
            'category_id' => ['required', Rule::exists('categories', 'id')->where('tenant_id', $tenant->id)],
            'name' => ['required', 'string', 'max:120'],
            'phone_code' => ['nullable', Rule::in(array_keys(PhoneCodes::ALL))],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9 +\-]{5,20}$/'],
            'address' => ['nullable', 'string', 'max:255'],
            'notes' => ['required', 'string', 'max:1000'],
            'due_date' => ['nullable', 'date'],
            'due_repeat' => ['required', Rule::in(array_keys(Account::DUE_REPEATS))],
            'tagIds' => ['array'],
            'tagIds.*' => ['integer', Rule::exists('tags', 'id')->where('tenant_id', $tenant->id)],
        ], attributes: [
            'category_id' => 'القسم', 'name' => 'الاسم الكامل', 'phone' => 'رقم الهاتف',
            'address' => 'العنوان', 'notes' => 'الملاحظات', 'due_date' => 'موعد السداد',
        ]);

        $tagIds = $data['tagIds'];
        unset($data['tagIds']);
        $data['phone'] = $data['phone'] ?: null;
        $data['due_date'] = $data['due_date'] ?: null;

        if ($this->account) {
            $this->account->update($data);
            $this->account->tags()->sync($tagIds);
            session()->flash('success', 'تم حفظ التعديلات.');

            return $this->redirectRoute('tenant.accounts.show', $this->account, navigate: true);
        }

        $account = Account::create($data);
        $account->tags()->sync($tagIds);
        session()->flash('success', 'تمت إضافة '.$account->name.'.');

        return $this->redirectRoute('tenant.accounts.show', $account, navigate: true);
    }

    public function delete()
    {
        abort_unless(auth()->user()->can('delete-records'), 403);
        $this->account?->delete();
        session()->flash('success', 'تم نقل الحساب إلى سلة المحذوفات.');

        return $this->redirectRoute('dashboard', navigate: true);
    }

    public function render()
    {
        return $this->view()->title($this->account ? 'تعديل حساب' : 'شخص جديد');
    }
}; ?>

<div class="pb-16">
    <x-dd.topbar :title="$account ? 'تعديل '.$account->name : 'إضافة شخص جديد'" :back="$account ? route('tenant.accounts.show', $account) : route('dashboard')" />

    <form wire:submit="save" class="space-y-6 px-5">
        <section class="dd-card p-6">
            <h2 class="mb-4 flex items-center gap-2 font-bold text-slate-600 dark:text-slate-300"><flux:icon.folder variant="mini" /> القسم</h2>
            <div class="flex flex-wrap gap-3" role="radiogroup">
                @foreach ($this->categories as $category)
                    <label wire:key="cat-{{ $category->id }}" @class([
                        'dd-chip cursor-pointer border-2',
                        'border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-900' => $category_id === $category->id,
                        'border-transparent '.Palette::badge($category->color) => $category_id !== $category->id,
                    ])>
                        <input type="radio" wire:model.live="category_id" value="{{ $category->id }}" class="sr-only">
                        <span class="size-2.5 rounded-full {{ Palette::DOT[$category->color] ?? 'bg-slate-500' }}"></span>
                        {{ $category->name }}
                    </label>
                @endforeach
                @can('manage-settings')
                    <a href="{{ route('tenant.categories') }}" wire:navigate class="dd-chip border-2 border-dashed border-slate-300 text-slate-500"><flux:icon.plus variant="micro" /> قسم جديد</a>
                @endcan
            </div>
            @error('category_id') <p class="dd-error">{{ $message }}</p> @enderror
        </section>

        <section class="dd-card space-y-5 p-6">
            <h2 class="flex items-center gap-2 font-bold text-slate-600 dark:text-slate-300"><flux:icon.identification variant="mini" /> بيانات الشخص</h2>

            <div>
                <label for="name" class="dd-label">الاسم الكامل <span class="text-rose-500">*</span></label>
                <input id="name" type="text" wire:model="name" class="dd-input" placeholder="مثال: محمد أحمد" autofocus>
                @error('name') <p class="dd-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="phone" class="dd-label">رقم الهاتف (للواتساب) <span class="text-slate-400">— اختياري</span></label>
                <div class="flex gap-2" dir="ltr">
                    <select wire:model="phone_code" class="dd-input w-36 shrink-0 px-3" aria-label="مفتاح الدولة">
                        @foreach (PhoneCodes::ALL as $code => [$flag, $country])
                            <option value="{{ $code }}">{{ $flag }} +{{ $code }}</option>
                        @endforeach
                    </select>
                    <input id="phone" type="tel" inputmode="tel" wire:model="phone" class="dd-input" placeholder="59 123 4567">
                </div>
                @error('phone') <p class="dd-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="address" class="dd-label">العنوان <span class="text-slate-400">— اختياري</span></label>
                <input id="address" type="text" wire:model="address" class="dd-input" placeholder="المدينة، الحي، الشارع">
                @error('address') <p class="dd-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="notes" class="dd-label">الملاحظات <span class="text-rose-500">*</span></label>
                <textarea id="notes" rows="3" wire:model="notes" class="dd-input" placeholder="مثال: عميل جملة، يسدد نهاية كل شهر"></textarea>
                @error('notes') <p class="dd-error">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="dd-card space-y-5 p-6">
            <h2 class="flex items-center gap-2 font-bold text-slate-600 dark:text-slate-300"><flux:icon.tag variant="mini" /> الوسوم <span class="text-sm font-normal text-slate-400">— اختياري، للتصفية (جملة، مفرق، قريب…)</span></h2>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->allTags as $t)
                    <button type="button" wire:key="tg-{{ $t->id }}" wire:click="toggleTag({{ $t->id }})" @class([
                        'dd-chip border-2 px-3 py-1.5',
                        'border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-900' => in_array($t->id, $tagIds, true),
                        'border-transparent '.Palette::badge($t->color) => ! in_array($t->id, $tagIds, true),
                    ])># {{ $t->name }}</button>
                @endforeach
            </div>
            <div class="flex gap-2">
                <input type="text" wire:model="newTag" wire:keydown.enter.prevent="addTag" placeholder="وسم جديد…" maxlength="40" class="dd-input">
                <button type="button" wire:click="addTag" class="dd-btn-ghost shrink-0 px-4"><flux:icon.plus variant="mini" /> إضافة</button>
            </div>
            @error('newTag') <p class="dd-error">{{ $message }}</p> @enderror
        </section>

        <section class="dd-card space-y-4 p-6">
            <h2 class="flex items-center gap-2 font-bold text-slate-600 dark:text-slate-300"><flux:icon.bell-alert variant="mini" /> موعد السداد <span class="text-sm font-normal text-slate-400">— اختياري</span></h2>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="dd-label">التاريخ</label>
                    <input type="date" wire:model="due_date" class="dd-input" dir="ltr">
                    @error('due_date') <p class="dd-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="dd-label">التكرار</label>
                    <select wire:model="due_repeat" class="dd-input">
                        @foreach (Account::DUE_REPEATS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <p class="text-sm text-slate-500">يظهر الحساب في صفحة «التحصيل» قبل موعده بأسبوع مع زر تذكير واتساب. عند السداد ينتقل الموعد تلقائياً للفترة التالية (أو يُلغى إذا كان لمرة واحدة وتمت التسوية).</p>
        </section>

        <button type="submit" class="dd-btn-primary w-full py-4 text-lg" wire:loading.attr="disabled">
            <flux:icon.check-circle variant="solid" class="size-6" />
            {{ $account ? 'حفظ التعديلات' : 'إضافة الشخص' }}
        </button>

        @if ($account && auth()->user()->can('delete-records'))
            <button type="button" wire:click="delete" wire:confirm="نقل «{{ $account->name }}» وجميع معاملاته إلى سلة المحذوفات؟" class="dd-btn w-full border border-rose-200 bg-rose-50 text-rose-600 dark:border-rose-500/20 dark:bg-rose-500/10">
                <flux:icon.trash variant="mini" /> حذف الحساب
            </button>
        @endif
    </form>
</div>
