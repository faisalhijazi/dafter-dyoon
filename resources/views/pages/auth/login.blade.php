@php($input = 'w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-left outline-none transition focus:border-indigo-400 focus:bg-white focus:ring-4 focus:ring-indigo-100')

<x-layouts::auth title="تسجيل الدخول">
    <div class="flex flex-col gap-6">
        <div class="text-center">
            <div class="text-sm font-medium text-indigo-600">مرحباً بعودتك</div>
            <h1 class="mt-2 text-3xl font-black text-slate-900">تسجيل الدخول</h1>
            <p class="mt-2 text-slate-500">أدخل بريدك وكلمة المرور للوصول إلى دفترك</p>
        </div>

        <x-auth-session-status class="rounded-2xl bg-emerald-50 px-4 py-3 text-center ring-1 ring-emerald-200" :status="session('status')" />

        {{-- @chisel-passkeys --}}
        <x-passkey-verify label="الدخول بمفتاح المرور" loading-label="جارٍ التحقق..." separator="أو تابع بالبريد الإلكتروني" />
        {{-- @end-chisel-passkeys --}}

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-5">
            @csrf

            <div>
                <label for="email" class="mb-2 block text-sm font-medium text-slate-600">البريد الإلكتروني</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="email" dir="ltr" placeholder="email@example.com" class="{{ $input }}">
                @error('email') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div x-data="{ show: false }">
                <div class="mb-2 flex items-center justify-between">
                    <label for="password" class="text-sm font-medium text-slate-600">كلمة المرور</label>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-700">نسيت كلمة المرور؟</a>
                    @endif
                </div>
                <div class="relative">
                    <input id="password" name="password" x-bind:type="show ? 'text' : 'password'" type="password" required autocomplete="current-password" dir="ltr" class="{{ $input }} pl-12">
                    <button type="button" x-on:click="show = ! show" class="absolute inset-y-0 left-3 flex items-center text-slate-400 hover:text-slate-600" x-bind:aria-label="show ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'">
                        <flux:icon.eye x-show="! show" class="size-5" />
                        <flux:icon.eye-slash x-show="show" x-cloak class="size-5" />
                    </button>
                </div>
                @error('password') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1" @checked(old('remember')) class="size-4 rounded border-slate-300 text-indigo-600">
                تذكرني
            </label>

            <button type="submit" data-test="login-button" class="w-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 px-5 py-3 font-bold text-white shadow-lg shadow-emerald-500/25 transition hover:opacity-95">تسجيل الدخول</button>
        </form>

        {{-- @chisel-registration --}}
        <p class="text-center text-sm text-slate-500">
            ليس لديك حساب؟
            <a href="{{ route('register') }}" class="font-bold text-indigo-600 hover:text-indigo-700">أنشئ حساباً مجاناً</a>
        </p>
        {{-- @end-chisel-registration --}}
    </div>
</x-layouts::auth>
