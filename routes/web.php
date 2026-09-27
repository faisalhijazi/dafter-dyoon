<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AssistantLearningController;
use App\Http\Controllers\Admin\Auth\AdminLoginController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\PublicStatementController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\Tenant\AttachmentController;
use App\Http\Controllers\Tenant\BillingController;
use App\Http\Controllers\Tenant\GoogleDriveController;
use App\Http\Controllers\Tenant\OfflineController;
use App\Http\Controllers\Tenant\ReportController;
use Illuminate\Support\Facades\Route;





Route::get('/', LandingController::class)->name('home');

// Stripe → subscription events (signature-verified; exempt from CSRF in bootstrap/app.php).
Route::post('stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

// Live account statement shared with a shop's customer (secret link, no login).
Route::get('s/{token}', [PublicStatementController::class, 'show'])->middleware('throttle:60,1')->name('statement.show');
Route::post('s/{token}/dispute', [PublicStatementController::class, 'dispute'])->middleware('throttle:5,1')->name('statement.dispute');
Route::post('s/{token}/confirm', [PublicStatementController::class, 'confirm'])->middleware('throttle:20,1')->name('statement.confirm');
Route::post('s/{token}/payment', [PublicStatementController::class, 'reportPayment'])->middleware('throttle:5,1')->name('statement.payment');

/*
|--------------------------------------------------------------------------
| Tenant dashboard (store owners)
|--------------------------------------------------------------------------
*/
Route::redirect('dashboard', '/app');

Route::middleware(['auth', 'verified', 'tenant'])->prefix('app')->group(function () {
    Route::livewire('/', 'pages::tenant.dashboard')->name('dashboard');

    Route::name('tenant.')->group(function () {
        // Everyone on the team (recording entries is always allowed).
        Route::livewire('accounts/create', 'pages::tenant.account-form')->name('accounts.create');
        Route::livewire('accounts/{account}/edit', 'pages::tenant.account-form')->name('accounts.edit');
        Route::livewire('accounts/{account}', 'pages::tenant.account-ledger')->name('accounts.show');
        Route::get('accounts/{account}/print', [ReportController::class, 'accountStatement'])->name('accounts.print');
        Route::get('attachments/{transaction}', AttachmentController::class)->name('attachments.show');
        Route::get('payment-reports/{paymentReport}/receipt', [ReportController::class, 'receipt'])->name('payment-reports.receipt');

        Route::livewire('quick-entry', 'pages::tenant.quick-entry')->name('quick-entry');
        Route::livewire('transfer', 'pages::tenant.transfer')->name('transfer');
        Route::livewire('split', 'pages::tenant.split')->name('split');
        Route::livewire('collections', 'pages::tenant.collections')->name('collections');
        Route::livewire('assistant', 'pages::tenant.assistant')->name('assistant');
        Route::livewire('preferences', 'pages::tenant.settings')->name('settings');
        Route::livewire('upgrade', 'pages::tenant.upgrade')->name('upgrade');

        Route::middleware('can:manage-billing')->group(function () {
            Route::post('billing/checkout', [BillingController::class, 'checkout'])->middleware('throttle:10,1')->name('billing.checkout');
            Route::get('billing/success', [BillingController::class, 'success'])->name('billing.success');
            Route::post('billing/portal', [BillingController::class, 'portal'])->middleware('throttle:10,1')->name('billing.portal');
        });

        // Offline entry: the service worker serves this page when there is no connection.
        Route::get('offline', [OfflineController::class, 'page'])->name('offline');
        Route::post('offline/sync', [OfflineController::class, 'sync'])->name('offline.sync');

        Route::middleware('can:view-reports')->group(function () {
            Route::livewire('statement', 'pages::tenant.statement')->name('statement');
            Route::livewire('insights', 'pages::tenant.insights')->name('insights');
            Route::get('reports/aging', [ReportController::class, 'aging'])->name('reports.aging');
            Route::get('reports/monthly', [ReportController::class, 'monthly'])->name('reports.monthly');
        });

        Route::middleware('can:manage-settings')->group(function () {
            Route::livewire('categories', 'pages::tenant.categories')->name('categories');
            Route::livewire('currencies', 'pages::tenant.currencies')->name('currencies');
            Route::livewire('currencies/create', 'pages::tenant.currency-form')->name('currencies.create');
            Route::livewire('currencies/{currency}/edit', 'pages::tenant.currency-form')->name('currencies.edit');
            Route::livewire('limits', 'pages::tenant.limits')->name('limits');
            Route::livewire('backup', 'pages::tenant.backup')->name('backup');
            Route::get('backup/google/connect', [GoogleDriveController::class, 'redirect'])->name('google.connect');
            Route::get('backup/google/callback', [GoogleDriveController::class, 'callback'])->name('google.callback');
        });

        Route::livewire('trash', 'pages::tenant.trash')->middleware('can:delete-records')->name('trash');
        Route::livewire('team', 'pages::tenant.team')->middleware('can:manage-team')->name('team');
        Route::livewire('activity', 'pages::tenant.activity')->middleware('can:view-activity')->name('activity');
    });
});

/*
|--------------------------------------------------------------------------
| Super admin panel (separate "admin" guard / admins table)
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [AdminLoginController::class, 'showLoginForm'])->name('login');
        Route::post('login', [AdminLoginController::class, 'login'])->middleware('throttle:5,1')->name('login.submit');
    });

    Route::middleware('auth:admin')->group(function () {
        Route::redirect('/', '/admin/dashboard');
        Route::get('dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::get('tenants/{tenant}', [TenantController::class, 'show'])->name('tenants.show');
        Route::put('tenants/{tenant}', [TenantController::class, 'update'])->name('tenants.update');
        Route::patch('tenants/{tenant}/status', [TenantController::class, 'toggleStatus'])->name('tenants.status');
        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
        Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
        Route::get('assistant', [AssistantLearningController::class, 'index'])->name('assistant.index');
        Route::post('assistant/review', [AssistantLearningController::class, 'review'])->name('assistant.review');
        Route::delete('assistant/reviews/{review}', [AssistantLearningController::class, 'destroyReview'])->name('assistant.reviews.destroy');
        Route::post('assistant/train', [AssistantLearningController::class, 'train'])->name('assistant.train');
        Route::post('assistant/versions/{version}/activate', [AssistantLearningController::class, 'activate'])->name('assistant.versions.activate');
        Route::post('assistant/reset', [AssistantLearningController::class, 'reset'])->name('assistant.reset');
        Route::post('logout', [AdminLoginController::class, 'logout'])->name('logout');
    });
});

require __DIR__.'/settings.php';
