<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Livewire\Actions\Logout;
use App\Services\TenantProvisioner;
use App\Support\Palette;
/* @chisel-email-verification */
use Illuminate\Contracts\Auth\MustVerifyEmail;
/* @end-chisel-email-verification */
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('الملف الشخصي')] class extends Component {
    use PasswordValidationRules, ProfileValidationRules;

    public string $name = '';
    public string $email = '';

    public bool $showDelete = false;
    public string $password = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate($this->profileRules($user->id), attributes: ['name' => 'الاسم', 'email' => 'البريد الإلكتروني']);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('toast', message: 'تم حفظ بياناتك.');
    }

    /* @chisel-email-verification */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }
    /* @end-chisel-email-verification */

    #[Computed]
    public function isOwner(): bool
    {
        return Auth::user()->role === 'owner';
    }

    /**
     * Delete the current user. An owner's account is the shop itself, so the whole
     * tenant and its data go with it instead of being left behind without an owner.
     */
    public function deleteUser(Logout $logout, TenantProvisioner $provisioner): void
    {
        $this->validate(['password' => $this->currentPasswordRules()], attributes: ['password' => 'كلمة المرور']);

        $user = Auth::user();
        $tenant = $this->isOwner ? $user->tenant : null;

        $logout();
        $tenant ? $provisioner->delete($tenant) : $user->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

@php($user = auth()->user())

<div class="pb-16">
    <x-dd.hero title="الملف الشخصي" subtitle="بياناتك الشخصية وبيانات الدخول" :back="route('tenant.settings')" icon="user-circle" />

    <div class="space-y-8 px-5 pt-8">
        <section class="dd-card flex items-center gap-4 p-5">
            <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl text-2xl font-bold shadow-lg {{ Palette::tile('indigo') }}">{{ \Illuminate\Support\Str::substr($user->name, 0, 1) }}</span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-xl font-bold">{{ $user->name }}</p>
                <p class="truncate text-sm text-slate-500" dir="ltr">{{ $user->email }}</p>
            </div>
            <div class="text-left">
                <span class="dd-badge bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-300">{{ $this->isOwner ? 'صاحب المتجر' : 'مستخدم' }}</span>
                <p class="mt-1 truncate text-xs text-slate-500">{{ $user->tenant?->name }}</p>
            </div>
        </section>

        <section>
            <h2 class="dd-section-title mb-3 text-slate-500">البيانات الشخصية</h2>
            <form wire:submit="updateProfileInformation" class="dd-card space-y-5 p-5">
                <div>
                    <label for="name" class="dd-label">الاسم</label>
                    <input id="name" type="text" wire:model="name" required autocomplete="name" class="dd-input">
                    @error('name') <p class="dd-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email" class="dd-label">البريد الإلكتروني</label>
                    <input id="email" type="email" wire:model="email" required autocomplete="email" dir="ltr" class="dd-input text-left">
                    @error('email') <p class="dd-error">{{ $message }}</p> @enderror

                    {{-- @chisel-email-verification --}}
                    @if ($this->hasUnverifiedEmail)
                        <div class="mt-3 rounded-2xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                            بريدك الإلكتروني غير مؤكَّد.
                            <button type="button" wire:click="resendVerificationNotification" class="font-bold underline">أعد إرسال رسالة التأكيد</button>
                            @if (session('status') === 'verification-link-sent')
                                <p class="mt-2 font-medium text-emerald-700 dark:text-emerald-400">تم إرسال رابط تأكيد جديد إلى بريدك.</p>
                            @endif
                        </div>
                    @endif
                    {{-- @end-chisel-email-verification --}}
                </div>

                <button type="submit" class="dd-btn-primary w-full" data-test="update-profile-button">
                    <span wire:loading.remove wire:target="updateProfileInformation">حفظ التغييرات</span>
                    <span wire:loading wire:target="updateProfileInformation">جارٍ الحفظ...</span>
                </button>
            </form>
        </section>

        <section>
            <h2 class="dd-section-title mb-2 text-slate-500">الأمان</h2>
            <div class="dd-card px-5">
                <x-dd.menu-row :href="route('security.edit')" icon="lock-closed" color="violet" title="الأمان وكلمة المرور" subtitle="تغيير كلمة المرور، التحقق بخطوتين، مفاتيح المرور" />
            </div>
        </section>

        <section>
            <h2 class="dd-section-title mb-3 text-rose-500">منطقة الخطر</h2>
            <div class="dd-card border border-rose-100 p-5 dark:border-rose-500/20">
                <p class="font-bold">حذف الحساب</p>
                <p class="mt-1 text-sm text-slate-500">
                    @if ($this->isOwner)
                        سيُحذف متجر «{{ $user->tenant?->name }}» نهائياً مع كل حساباته ومعاملاته وصوره ومستخدميه. أنشئ نسخة احتياطية قبل ذلك إن أردت الاحتفاظ بالبيانات.
                    @else
                        سيُحذف حسابك فقط، وتبقى بيانات المتجر كما هي.
                    @endif
                </p>
                <button type="button" wire:click="$set('showDelete', true)" class="dd-btn mt-4 w-full border border-rose-200 text-rose-600 dark:border-rose-500/30" data-test="delete-user-button">
                    <flux:icon.trash variant="micro" /> حذف الحساب
                </button>
            </div>
        </section>
    </div>

    <x-dd.sheet model="showDelete" title="تأكيد حذف الحساب">
        <form wire:submit="deleteUser" class="space-y-4">
            <div class="rounded-2xl bg-rose-50 p-4 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                {{ $this->isOwner ? 'لا يمكن التراجع عن هذه العملية: سيُحذف المتجر وكل بياناته نهائياً.' : 'لا يمكن التراجع عن هذه العملية.' }}
            </div>
            <div>
                <label for="delete-password" class="dd-label">أدخل كلمة المرور للتأكيد</label>
                <input id="delete-password" type="password" wire:model="password" autocomplete="current-password" dir="ltr" class="dd-input text-left">
                @error('password') <p class="dd-error">{{ $message }}</p> @enderror
            </div>
            <button type="submit" class="dd-btn w-full bg-rose-600 text-white" data-test="confirm-delete-user-button">حذف الحساب نهائياً</button>
        </form>
    </x-dd.sheet>
</div>
