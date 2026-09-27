<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name', 'slug', 'plan_id', 'status', 'subscription_ends_at',
        'stripe_customer_id', 'stripe_subscription_id', 'subscription_status', 'subscription_interval', 'subscription_cancels',
        'default_debt_limit', 'settings', 'google_email', 'google_token',
    ];

    protected $hidden = ['google_token'];

    protected function casts(): array
    {
        return [
            'subscription_ends_at' => 'datetime',
            'subscription_cancels' => 'boolean',
            'default_debt_limit' => 'decimal:2',
            'settings' => 'array',
            'google_token' => 'encrypted:array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function owner(): HasOne
    {
        return $this->hasOne(User::class)->where('role', 'owner')->oldestOfMany();
    }

    // Relations below bypass the tenant scope so they also work from the admin panel.
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class)->withoutGlobalScope('tenant');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->withoutGlobalScope('tenant');
    }

    public function currencies(): HasMany
    {
        return $this->hasMany(Currency::class)->withoutGlobalScope('tenant');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class)->withoutGlobalScope('tenant');
    }

    public function backupLogs(): HasMany
    {
        return $this->hasMany(BackupLog::class)->withoutGlobalScope('tenant');
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /** Billed through Stripe (as opposed to activated by hand from the admin panel). */
    public function hasStripeSubscription(): bool
    {
        return $this->stripe_subscription_id !== null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPro(): bool
    {
        return $this->plan && ! $this->plan->isFree()
            && (! $this->subscription_ends_at || $this->subscription_ends_at->isFuture());
    }

    /**
     * The plan in effect: an expired paid subscription falls back to the free plan.
     */
    public function effectivePlan(): ?Plan
    {
        if ($this->plan && ($this->plan->isFree() || $this->isPro())) {
            return $this->plan;
        }

        return Plan::where('price_monthly', 0)->orderBy('sort_order')->first() ?? $this->plan;
    }

    public function canUse(string $feature): bool
    {
        return (bool) $this->effectivePlan()?->{$feature};
    }

    /**
     * Plans without multi_currency still get their base currency plus the plan's
     * `free_currencies` (the free plan: shekel and dollar).
     */
    public function canUseCurrency(Currency $currency): bool
    {
        if ($currency->is_base || $this->canUse('multi_currency')) {
            return true;
        }

        return $currency->iso_code !== null
            && in_array($currency->iso_code, $this->effectivePlan()?->free_currencies ?? [], true);
    }

    /**
     * Whether the tenant may create one more record of the given limit ('accounts' | 'transactions').
     */
    public function withinLimit(string $resource): bool
    {
        $max = $this->effectivePlan()?->{'max_'.$resource};

        return $max === null || $max > $this->{$resource}()->count();
    }

    /** Staff seats on the plan: null = unlimited, 0 = owner only. */
    public function staffLimit(): ?int
    {
        $plan = $this->effectivePlan();

        return $plan ? $plan->max_staff : 0;
    }

    public function canAddStaff(): bool
    {
        $limit = $this->staffLimit();

        return $limit === null || $this->users()->where('role', User::ROLE_STAFF)->count() < $limit;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }
}
