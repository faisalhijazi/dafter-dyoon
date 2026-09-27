<?php

namespace App\Providers;

use App\Http\Middleware\EnsureTenantIsActive;
use App\Models\Account;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\Observers\AccountObserver;
use App\Observers\CurrencyObserver;
use App\Observers\TransactionObserver;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Re-check tenant status on every Livewire update request, not only the first page load.
        Livewire::addPersistentMiddleware([EnsureTenantIsActive::class]);

        CarbonImmutable::setLocale('ar');

        // Activity log + collections engine react to every ledger change.
        Transaction::observe(TransactionObserver::class);
        Account::observe(AccountObserver::class);
        Currency::observe(CurrencyObserver::class);

        $this->configureGates();
    }

    /**
     * Staff permissions. Owners pass every gate; staff only what the owner granted.
     * Recording entries is always allowed.
     */
    protected function configureGates(): void
    {
        Gate::define('delete-records', fn (User $user) => $user->hasPermission('delete'));
        Gate::define('view-reports', fn (User $user) => $user->hasPermission('reports'));
        Gate::define('manage-settings', fn (User $user) => $user->hasPermission('settings'));
        Gate::define('manage-team', fn (User $user) => $user->isOwner());
        Gate::define('view-activity', fn (User $user) => $user->isOwner());
        Gate::define('manage-billing', fn (User $user) => $user->isOwner());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
