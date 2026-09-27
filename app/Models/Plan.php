<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'price_monthly', 'price_yearly',
        'max_accounts', 'max_transactions', 'max_staff', 'multi_currency', 'free_currencies', 'debt_limits',
        'cloud_backup', 'live_statement', 'features', 'is_active', 'sort_order', 'stripe_product_id',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'max_accounts' => 'integer',
            'max_transactions' => 'integer',
            'max_staff' => 'integer',
            'multi_currency' => 'boolean',
            'debt_limits' => 'boolean',
            'cloud_backup' => 'boolean',
            'live_statement' => 'boolean',
            'free_currencies' => 'array',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function isFree(): bool
    {
        return (float) $this->price_monthly === 0.0;
    }
}
