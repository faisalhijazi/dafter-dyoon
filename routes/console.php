<?php

use App\Models\PaymentPromise;
use App\Models\Tenant;
use App\Services\StripeBilling;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// «مساعد AI» self-learning: retrain nightly from what merchants taught it (requires the scheduler: `php artisan schedule:work` or a cron running `schedule:run`).
Schedule::command('assistant:learn')->dailyAt(config('assistant.learning.schedule'))->withoutOverlapping()->onOneServer();

// Collections: promises whose day passed without payment become "broken" (all tenants).
Artisan::command('collections:daily', function () {
    $broken = PaymentPromise::withoutGlobalScope('tenant')
        ->where('status', PaymentPromise::PENDING)
        ->where('promised_on', '<', now()->toDateString())
        ->update(['status' => PaymentPromise::BROKEN, 'resolved_at' => now()]);

    $this->info("Broken promises: {$broken}");
})->purpose('Mark overdue payment promises as broken');

Schedule::command('collections:daily')->dailyAt('00:10')->withoutOverlapping()->onOneServer();

// Billing safety net: re-read Stripe subscriptions that are about to lapse, in case a webhook was missed.
Artisan::command('billing:sync', function (StripeBilling $stripe) {
    if (! $stripe->isConfigured()) {
        return $this->warn('Stripe is not configured.');
    }

    $tenants = Tenant::whereNotNull('stripe_subscription_id')
        ->where(fn ($q) => $q->whereNull('subscription_ends_at')->orWhere('subscription_ends_at', '<', now()->addDays(3)))
        ->get();

    foreach ($tenants as $tenant) {
        try {
            $stripe->refresh($tenant);
        } catch (RuntimeException $e) {
            $this->error("#{$tenant->id}: {$e->getMessage()}");
        }
    }

    $this->info("Synced: {$tenants->count()}");
})->purpose('Refresh Stripe subscriptions close to expiry');

Schedule::command('billing:sync')->dailyAt('01:30')->withoutOverlapping()->onOneServer();
