<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes the tenant's activity log ("سجل النشاط"). Called by model observers and by
 * services for actions that bypass model events (moves, restores, team changes…).
 */
class ActivityLogger
{
    private bool $paused = false;

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(string $event, string $description, ?Model $subject = null, array $properties = [], ?int $tenantId = null): ?ActivityLog
    {
        $tenantId ??= $subject?->getAttribute('tenant_id') ?? Auth::guard('web')->user()?->tenant_id;

        if ($this->paused || ! $tenantId) {
            return null;
        }

        return ActivityLog::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenantId,
            'user_id' => Auth::guard('web')->id(),
            'event' => $event,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => mb_substr($description, 0, 500),
            'properties' => $properties ?: null,
            'ip' => Request::ip(),
        ]);
    }

    /** Run without logging (bulk restores, seeding). */
    public function quietly(callable $callback): mixed
    {
        [$previous, $this->paused] = [$this->paused, true];

        try {
            return $callback();
        } finally {
            $this->paused = $previous;
        }
    }
}
