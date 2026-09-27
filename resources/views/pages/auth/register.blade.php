@php($input = 'w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 outline-none transition focus:border-indigo-400 focus:bg-white focus:ring-4 focus:ring-indigo-100')
@php($passwordRules = \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString())

<x-layouts::auth title="إنشاء حساب">
    <div class="flex flex-col gap-6">
        <div class="text-center">
            <div class="text-sm font-medium text-emerald-600">ابدأ مجاناً</div>
            <h1 class="mt-2 text-3xl font-black text-slate-900">إنشاء حساب جديد</h1>
            <p class="mt-2 text-slate-500">دقيقة واحدة وتبدأ بتسجيل ديونك وحساباتك</p>
        </div>

        <x-auth-session-status class="rounded-2xl bg-emerald-50 px-4 py-3 text-center ring-1 ring-emerald-200" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-5" x-data="{ show: false }">
            @csrf

            <div>
                <label for="name" class="mb-2 block text-sm font-medium text-slate-600">الاسم الكامل</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}" required autofocus autocomplete="name" placeholder="مثال: محمد أحمد" class="{{ $input }}">
                @error('name') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="business_name" class="mb-2 block text-sm font-medium text-slate-600">اسم المتجر أو النشاط <span class="text-slate-400">— اختياري</span></label>
                <input id="business_name" name="business_name" type="text" value="{{ old('business_name') }}" autocomplete="organization" placeholder="مثال: متجر النور" class="{{ $input }}">
                @error('business_name') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="email" class="mb-2 block text-sm font-medium text-slate-600">البريد الإلكتروني</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email" dir="ltr" placeholder="email@example.com" class="{{ $input }} text-left">
                @error('email') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="password" class="mb-2 block text-sm font-medium text-slate-600">كلمة المرور</label>
                    <input id="password" name="password" x-bind:type="show ? 'text' : 'password'" type="password" required autocomplete="new-password" passwordrules="{{ $passwordRules }}" dir="ltr" class="{{ $input }} text-left">
                </div>
                <div>
                    <label for="password_confirmation" class="mb-2 block text-sm font-medium text-slate-600">تأكيد كلمة المرور</label>
                    <input id="password_confirmation" name="password_confirmation" x-bind:type="show ? 'text' : 'password'" type="password" required autocomplete="new-password" passwordrules="{{ $passwordRules }}" dir="ltr" class="{{ $input }} text-left">
                </div>
            </div>
            @error('password') <p class="-mt-3 text-sm text-rose-600">{{ $message }}</p> @enderror

            <label class="-mt-2 flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" x-model="show" class="size-4 rounded border-slate-300 text-indigo-600">
                إظهار كلمة المرور
            </label>

            <button type="submit" data-test="register-user-button" class="w-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 px-5 py-3 font-bold text-white shadow-lg shadow-emerald-500/25 transition hover:opacity-95">إنشاء الحساب</button>
        </form>

        <p class="text-center text-sm text-slate-500">
            لديك حساب بالفعل؟
            <a href="{{ route('login') }}" class="font-bold text-indigo-600 hover:text-indigo-700">تسجيل الدخول</a>
        </p>
    </div>
</x-layouts::auth>
