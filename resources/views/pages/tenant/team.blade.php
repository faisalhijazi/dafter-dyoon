<?php

use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('فريق العمل')] class extends Component {
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public array $permissions = [];
    public bool $active = true;

    #[Computed]
    public function members()
    {
        return User::where('tenant_id', auth()->user()->tenant_id)
            ->orderByRaw("role = 'owner' DESC")->orderBy('name')->get();
    }

    public function create(): void
    {
        if (! auth()->user()->tenant->canAddStaff()) {
            $this->redirectRoute('tenant.upgrade', navigate: true);

            return;
        }

        $this->resetValidation();
        $this->reset('editingId', 'name', 'email', 'permissions');
        $this->password = Str::password(10, symbols: false);
        $this->active = true;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $user = $this->staff($id);
        $this->resetValidation();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->permissions = $user->permissions ?? [];
        $this->active = (bool) $user->is_active;
        $this->showForm = true;
    }

    private function staff(int $id): User
    {
        return User::where('tenant_id', auth()->user()->tenant_id)->where('role', User::ROLE_STAFF)->findOrFail($id);
    }

    public function save(ActivityLogger $logger): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($this->editingId)],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', Password::min(8)],
            'permissions' => ['array'],
            'permissions.*' => [Rule::in(array_keys(User::PERMISSIONS))],
        ], attributes: ['name' => 'الاسم', 'email' => 'البريد الإلكتروني', 'password' => 'كلمة المرور']);

        $attributes = [
            'name' => $data['name'],
            'email' => mb_strtolower($data['email']),
            'permissions' => array_values($data['permissions']),
            'is_active' => $this->active,
        ];

        if ($data['password']) {
            $attributes['password'] = $data['password'];
        }

        if ($this->editingId) {
            $user = $this->staff($this->editingId);
            $user->update($attributes);
            $logger->log('team.updated', "عدّل صلاحيات الموظف {$user->name}", $user, ['permissions' => $attributes['permissions'], 'active' => $this->active]);
            $message = 'تم حفظ التعديلات.';
        } else {
            if (! auth()->user()->tenant->canAddStaff()) {
                $this->addError('name', 'وصلت للحد الأقصى من الموظفين في خطتك.');

                return;
            }

            $user = User::create([...$attributes, 'tenant_id' => auth()->user()->tenant_id, 'role' => User::ROLE_STAFF]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $logger->log('team.created', "أضاف الموظف {$user->name}", $user);
            $message = 'تمت إضافة '.$user->name.'. أرسل له البريد وكلمة المرور ليدخل.';
        }

        $this->showForm = false;
        unset($this->members);
        $this->dispatch('toast', message: $message);
    }

    public function toggle(int $id, ActivityLogger $logger): void
    {
        $user = $this->staff($id);
        $user->update(['is_active' => ! $user->is_active]);
        $logger->log('team.updated', ($user->is_active ? 'فعّل' : 'أوقف').' حساب الموظف '.$user->name, $user);
        unset($this->members);
    }

    public function remove(int $id, ActivityLogger $logger): void
    {
        $user = $this->staff($id);
        $logger->log('team.deleted', "حذف الموظف {$user->name}", $user);
        $user->delete();
        $this->showForm = false;
        unset($this->members);
        $this->dispatch('toast', message: 'تم حذف الموظف. تبقى معاملاته المسجلة كما هي.');
    }

    public function with(): array
    {
        $tenant = auth()->user()->tenant;

        return [
            'limit' => $tenant->staffLimit(),
            'used' => $this->members->where('role', User::ROLE_STAFF)->count(),
            'canAdd' => $tenant->canAddStaff(),
        ];
    }
}; ?>

<div class="pb-36">
    <x-dd.hero title="فريق العمل" subtitle="موظفون يسجّلون المعاملات بصلاحيات تحددها أنت" :back="route('tenant.settings')" icon="user-group" />

    <div class="relative -mt-4 space-y-4 px-5">
        <section class="dd-card p-5">
            <div class="flex items-center justify-between">
                <p class="text-slate-500">الموظفون في خطتك</p>
                <p class="text-xl font-black">{{ $used }} / {{ $limit === null ? '∞' : $limit }}</p>
            </div>
            @if ($limit === 0)
                <a href="{{ route('tenant.upgrade') }}" wire:navigate class="mt-3 flex items-center gap-2 rounded-2xl bg-amber-50 p-3 text-sm font-medium text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                    <flux:icon.lock-closed variant="mini" /> إضافة موظفين متاحة في الخطة الاحترافية.
                </a>
            @endif
            <p class="mt-3 text-sm text-slate-500">كل موظف يستطيع تسجيل المعاملات وإضافة الزبائن دائماً. الصلاحيات الأخرى تختارها أنت، وكل ما يفعله يظهر في «سجل النشاط».</p>
        </section>

        @foreach ($this->members as $member)
            <div wire:key="u-{{ $member->id }}" @class(['dd-card flex items-center gap-4 p-4', 'opacity-60' => ! $member->is_active])>
                <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl text-lg font-bold {{ $member->isOwner() ? \App\Support\Palette::tile('dark') : \App\Support\Palette::avatar($member->id) }}">{{ $member->initials() }}</span>
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 font-bold">
                        {{ $member->name }}
                        @if ($member->isOwner())
                            <span class="rounded-full bg-slate-900 px-2 py-0.5 text-[10px] text-white dark:bg-white dark:text-slate-900">المالك</span>
                        @elseif (! $member->is_active)
                            <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] text-rose-700">موقوف</span>
                        @endif
                    </p>
                    <p class="truncate text-sm text-slate-500" dir="ltr">{{ $member->email }}</p>
                    @unless ($member->isOwner())
                        <div class="mt-1.5 flex flex-wrap gap-1">
                            @forelse ($member->permissions ?? [] as $perm)
                                <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">{{ \Illuminate\Support\Str::before(\App\Models\User::PERMISSIONS[$perm] ?? $perm, ' (') }}</span>
                            @empty
                                <span class="text-[11px] text-slate-400">تسجيل المعاملات فقط</span>
                            @endforelse
                        </div>
                    @endunless
                </div>
                @unless ($member->isOwner())
                    <button type="button" wire:click="toggle({{ $member->id }})" class="dd-icon-btn size-10 bg-slate-100 text-slate-500 dark:bg-slate-800" title="{{ $member->is_active ? 'إيقاف' : 'تفعيل' }}">
                        <flux:icon :name="$member->is_active ? 'pause' : 'play'" variant="micro" />
                    </button>
                    <button type="button" wire:click="edit({{ $member->id }})" class="dd-icon-btn size-10 bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10" title="تعديل"><flux:icon.pencil-square variant="micro" /></button>
                @endunless
            </div>
        @endforeach
    </div>

    @if ($canAdd)
        <button type="button" wire:click="create" class="dd-fab"><flux:icon.user-plus variant="solid" class="size-6" /> إضافة موظف</button>
    @endif

    <x-dd.sheet model="showForm" :title="$editingId ? 'تعديل الموظف' : 'موظف جديد'">
        <form wire:submit="save" class="space-y-4">
            <div>
                <label class="dd-label">الاسم *</label>
                <input type="text" wire:model="name" class="dd-input">
                @error('name') <p class="dd-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="dd-label">البريد الإلكتروني (للدخول) *</label>
                <input type="email" wire:model="email" class="dd-input text-left" dir="ltr">
                @error('email') <p class="dd-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="dd-label">{{ $editingId ? 'كلمة مرور جديدة (اتركها فارغة للإبقاء)' : 'كلمة المرور *' }}</label>
                <div class="flex gap-2">
                    <input type="text" wire:model="password" class="dd-input text-left font-mono" dir="ltr" autocomplete="new-password">
                    @if ($password)
                        <button type="button" x-on:click="ddCopyToast(@js($password), 'تم نسخ كلمة المرور')" class="dd-icon-btn size-12 shrink-0 bg-slate-100 dark:bg-slate-800" title="نسخ"><flux:icon.document-duplicate variant="mini" /></button>
                    @endif
                </div>
                @error('password') <p class="dd-error">{{ $message }}</p> @enderror
            </div>

            <fieldset class="dd-soft space-y-2 p-4">
                <legend class="mb-1 text-sm font-bold text-slate-600 dark:text-slate-300">الصلاحيات الإضافية</legend>
                @foreach (\App\Models\User::PERMISSIONS as $key => $label)
                    <label class="flex items-center gap-3 rounded-xl bg-white p-3 dark:bg-slate-900">
                        <input type="checkbox" wire:model="permissions" value="{{ $key }}" class="size-5 rounded">
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
                <label class="flex items-center gap-3 rounded-xl bg-white p-3 dark:bg-slate-900">
                    <input type="checkbox" wire:model="active" class="size-5 rounded">
                    <span>الحساب مفعّل (يستطيع الدخول)</span>
                </label>
            </fieldset>

            <button type="submit" class="dd-btn-primary w-full">حفظ</button>
            @if ($editingId)
                <button type="button" wire:click="remove({{ $editingId }})" wire:confirm="حذف هذا الموظف؟" class="dd-btn w-full border border-rose-200 text-rose-600">حذف الموظف</button>
            @endif
        </form>
    </x-dd.sheet>
</div>
