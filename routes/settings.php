<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    // Rendered in the tenant app shell, so it needs an active tenant like the rest of /app.
    Route::livewire('settings/profile', 'pages::settings.profile')->middleware('tenant')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    // The appearance switch lives in the app's own settings page now.
    Route::redirect('settings/appearance', '/app/preferences');

    Route::livewire('settings/security', 'pages::settings.security')
        /* @chisel-password-confirmation */
        ->middleware([
            'tenant',
            'password.confirm',
        ])
        /* @end-chisel-password-confirmation */
        ->name('security.edit');
});

/* @chisel-passkeys */
Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
/* @end-chisel-passkeys */
