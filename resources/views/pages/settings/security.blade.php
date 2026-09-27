<?php

use App\Concerns\PasswordValidationRules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
/* @chisel-passkeys */
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Locked;
/* @end-chisel-passkeys */
/* @chisel-2fa */
use Livewire\Attributes\On;
/* @end-chisel-2fa */

new #[Layout('layouts::tenant')] #[Title('الأمان')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /* @chisel-2fa */
    public bool $canManageTwoFactor;

    public bool $twoFactorEnabled;

    public bool $requiresConfirmation;
    /* @end-chisel-2fa */

    /* @chisel-passkeys */
    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';
    /* @end-chisel-passkeys */

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        /* @chisel-2fa */
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }
        /* @end-chisel-2fa */

        /* @chisel-passkeys */
        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
        /* @end-chisel-passkeys */
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ], attributes: ['current_password' => 'كلمة المرور الحالية', 'password' => 'كلمة المرور الجديدة']);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('toast', message: 'تم تغيير كلمة المرور.');
    }

    /* @chisel-passkeys */
    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Show the delete confirmation modal.
     */
    public function confirmDelete(int $passkeyId): void
    {
        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;
    }

    /**
     * Delete the passkey.
     */
    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        if (! $this->deletingPasskeyId) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(auth()->user(), $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();
    }

    /**
     * Close the delete confirmation modal.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }
    /* @end-chisel-passkeys */

    /* @chisel-2fa */
    /**
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
        $this->dispatch('toast', message: 'تم تفعيل التحقق بخطوتين.');
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $disableTwoFactorAuthentication(auth()->user());

        $this->twoFactorEnabled = false;
        $this->dispatch('toast', message: 'تم إيقاف التحقق بخطوتين.');
    }
    /* @end-chisel-2fa */
}; ?>

@php($passwordRules = \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString())

<div class="pb-16">
    <x-dd.hero title="الأمان" subtitle="كلمة المرور وطرق حماية الدخول" :back="route('profile.edit')" icon="shield-check" />

    <div class="space-y-8 px-5 pt-8">
        {{-- Password --}}
        <section>
            <h2 class="mb-3 flex items-center gap-3 text-lg font-bold">
                <span class="flex size-11 items-center justify-center rounded-xl {{ \App\Support\Palette::tile('violet') }}"><flux:icon.key variant="solid" class="size-6" /></span>
                تغيير كلمة المرور
                <span class="h-px flex-1 bg-linear-to-l from-slate-200 to-transparent dark:from-slate-700"></span>
            </h2>
            <form wire:submit="updatePassword" class="dd-card space-y-5 p-5" x-data="{ show: false }">
                <p class="text-sm text-slate-500">استخدم كلمة مرور طويلة يصعب تخمينها، ولا تستخدمها في مواقع أخرى.</p>

                <div>
                    <label for="current_password" class="dd-label">كلمة المرور الحالية</label>
                    <input id="current_password" wire:model="current_password" x-bind:type="show ? 'text' : 'password'" type="password" required autocomplete="current-password" dir="ltr" class="dd-input text-left">
                    @error('current_password') <p class="dd-error">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="password" class="dd-label">كلمة المرور الجديدة</label>
                        <input id="password" wire:model="password" x-bind:type="show ? 'text' : 'password'" type="password" required autocomplete="new-password" passwordrules="{{ $passwordRules }}" dir="ltr" class="dd-input text-left">
                    </div>
                    <div>
                        <label for="password_confirmation" class="dd-label">تأكيد كلمة المرور</label>
                        <input id="password_confirmation" wire:model="password_confirmation" x-bind:type="show ? 'text' : 'password'" type="password" required autocomplete="new-password" passwordrules="{{ $passwordRules }}" dir="ltr" class="dd-input text-left">
                    </div>
                </div>
                @error('password') <p class="dd-error -mt-3">{{ $message }}</p> @enderror

                <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                    <input type="checkbox" x-model="show" class="size-4 rounded border-slate-300">
                    إظهار كلمات المرور
                </label>

                <button type="submit" class="dd-btn-primary w-full" data-test="update-password-button">
                    <span wire:loading.remove wire:target="updatePassword">حفظ كلمة المرور</span>
                    <span wire:loading wire:target="updatePassword">جارٍ الحفظ...</span>
                </button>
            </form>
        </section>

        {{-- @chisel-2fa --}}
        @if ($canManageTwoFactor)
            <section>
                <h2 class="mb-3 flex items-center gap-3 text-lg font-bold">
                    <span class="flex size-11 items-center justify-center rounded-xl {{ \App\Support\Palette::tile('emerald') }}"><flux:icon.device-phone-mobile variant="solid" class="size-6" /></span>
                    التحقق بخطوتين
                    <span class="h-px flex-1 bg-linear-to-l from-slate-200 to-transparent dark:from-slate-700"></span>
                </h2>

                <div class="dd-card space-y-5 p-5" wire:cloak>
                    <div class="flex items-start gap-4">
                        <span @class(['flex size-14 shrink-0 items-center justify-center rounded-2xl', 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10' => $twoFactorEnabled, 'bg-slate-100 text-slate-500 dark:bg-slate-800' => ! $twoFactorEnabled])>
                            <flux:icon :name="$twoFactorEnabled ? 'shield-check' : 'shield-exclamation'" variant="solid" class="size-7" />
                        </span>
                        <div class="flex-1">
                            <p class="flex items-center gap-2 text-lg font-bold">
                                {{ $twoFactorEnabled ? 'مفعّل' : 'غير مفعّل' }}
                                <span @class(['dd-badge text-xs', 'bg-emerald-100 text-emerald-700' => $twoFactorEnabled, 'bg-amber-100 text-amber-700' => ! $twoFactorEnabled])>{{ $twoFactorEnabled ? 'حسابك محمي' : 'يُنصح بتفعيله' }}</span>
                            </p>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ $twoFactorEnabled
                                    ? 'عند تسجيل الدخول يُطلب منك رمز من تطبيق المصادقة على جوالك إضافة لكلمة المرور.'
                                    : 'طبقة حماية إضافية: حتى لو عرف أحد كلمة مرورك، لن يدخل دون رمز من جوالك (Google Authenticator أو ما يشبهه).' }}
                            </p>
                        </div>
                    </div>

                    @if ($twoFactorEnabled)
                        <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />

                        <button type="button" wire:click="disable" wire:confirm="إيقاف التحقق بخطوتين؟ سيصبح حسابك أقل حماية." class="dd-btn w-full border border-rose-200 text-rose-600 dark:border-rose-500/30">
                            <flux:icon.no-symbol variant="micro" /> إيقاف التحقق بخطوتين
                        </button>
                    @else
                        <flux:modal.trigger name="two-factor-setup-modal">
                            <button type="button" wire:click="$dispatch('start-two-factor-setup')" class="dd-btn-primary w-full">
                                <flux:icon.shield-check variant="micro" /> تفعيل التحقق بخطوتين
                            </button>
                        </flux:modal.trigger>

                        <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                    @endif
                </div>
            </section>
        @endif
        {{-- @end-chisel-2fa --}}

        {{-- @chisel-passkeys --}}
        @if ($canManagePasskeys)
            <section>
                <h2 class="mb-3 flex items-center gap-3 text-lg font-bold">
                    <span class="flex size-11 items-center justify-center rounded-xl {{ \App\Support\Palette::tile('blue') }}"><flux:icon.finger-print variant="solid" class="size-6" /></span>
                    مفاتيح المرور
                    <span class="h-px flex-1 bg-linear-to-l from-slate-200 to-transparent dark:from-slate-700"></span>
                </h2>

                <div class="dd-card p-5" wire:cloak>
                    <p class="text-sm text-slate-500">سجّل الدخول ببصمة الإصبع أو الوجه أو قفل الجهاز، دون كتابة كلمة المرور.</p>

                    <div class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
                        @forelse ($passkeys as $passkey)
                            <div wire:key="passkey-{{ $passkey['id'] }}" class="flex items-center gap-4 py-4">
                                <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-blue-600 dark:bg-blue-500/10"><flux:icon.key variant="solid" class="size-6" /></span>
                                <div class="min-w-0 flex-1">
                                    <p class="flex items-center gap-2 font-bold">
                                        <span class="truncate">{{ $passkey['name'] }}</span>
                                        @if ($passkey['authenticator'])
                                            <span class="dd-badge bg-slate-100 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $passkey['authenticator'] }}</span>
                                        @endif
                                    </p>
                                    <p class="text-xs text-slate-500">
                                        أُضيف {{ $passkey['created_at_diff'] }}
                                        @if ($passkey['last_used_at_diff'])
                                            · آخر استخدام {{ $passkey['last_used_at_diff'] }}
                                        @endif
                                    </p>
                                </div>
                                <button type="button" wire:click="confirmDelete({{ $passkey['id'] }})" class="dd-icon-btn size-11 bg-rose-50 text-rose-500 dark:bg-rose-500/10" aria-label="حذف مفتاح المرور"><flux:icon.trash variant="solid" class="size-5" /></button>
                            </div>
                        @empty
                            <div class="flex flex-col items-center py-6 text-center">
                                <span class="flex size-16 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-800"><flux:icon.finger-print variant="solid" class="size-8" /></span>
                                <p class="mt-3 font-bold">لا توجد مفاتيح مرور بعد</p>
                            </div>
                        @endforelse
                    </div>

                    <div class="mt-4">
                        <x-passkey-registration />
                    </div>
                </div>
            </section>

            <x-dd.sheet model="showDeleteModal" title="حذف مفتاح المرور">
                <div class="space-y-4">
                    <p class="text-slate-600 dark:text-slate-300">هل تريد حذف مفتاح المرور «{{ $deletingPasskeyName }}»؟ لن تتمكن من استخدامه لتسجيل الدخول بعد ذلك.</p>
                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" wire:click="closeDeleteModal" class="dd-btn bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200">إلغاء</button>
                        <button type="button" wire:click="deletePasskey" class="dd-btn bg-rose-600 text-white">حذف</button>
                    </div>
                </div>
            </x-dd.sheet>
        @endif
        {{-- @end-chisel-passkeys --}}
    </div>
</div>
