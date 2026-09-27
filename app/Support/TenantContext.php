<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

/**
 * Holds the tenant for the current request / job.
 *
 * Resolution order: an explicitly set tenant (jobs, restores, seeders) and
 * otherwise the tenant of the user logged in on the "web" guard.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    private bool $explicit = false;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->explicit = $tenant !== null;
    }

    public function tenant(): ?Tenant
    {
        if ($this->explicit) {
            return $this->tenant;
        }

        $user = Auth::guard('web')->user();

        if (! $user) {
            return null;
        }

        if ($this->tenant?->id !== $user->tenant_id) {
            $this->tenant = $user->tenant;
        }

        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant()?->id;
    }

    /**
     * True when a tenant user is logged in, so tenant-owned queries must be scoped
     * (even if the user has no tenant, which then matches nothing).
     */
    public function mustScope(): bool
    {
        return $this->explicit || Auth::guard('web')->check();
    }

    /**
     * Run a callback as a given tenant, restoring the previous context afterwards.
     */
    public function runAs(Tenant $tenant, callable $callback): mixed
    {
        [$previous, $wasExplicit] = [$this->tenant, $this->explicit];
        $this->set($tenant);

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
            $this->explicit = $wasExplicit;
        }
    }
}
