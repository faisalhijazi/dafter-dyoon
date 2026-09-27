<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isolates a model's rows per tenant: every query is filtered by the current
 * tenant and new rows are stamped with its id automatically.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $context = app(TenantContext::class);

            if ($context->mustScope()) {
                // A logged-in user without a tenant must never see other tenants' rows.
                $builder->where($builder->qualifyColumn('tenant_id'), $context->id() ?? 0);
            }
        });

        static::creating(function ($model) {
            if (! $model->tenant_id && ($tenantId = app(TenantContext::class)->id())) {
                $model->tenant_id = $tenantId;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
