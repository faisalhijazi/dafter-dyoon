<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who did what and when (transaction added / edited / deleted, account changes, settings…).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $event
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $description
 * @property array<string, mixed>|null $properties
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 */
class ActivityLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'user_id', 'event', 'subject_type', 'subject_id', 'description', 'properties', 'ip'];

    protected function casts(): array
    {
        return ['properties' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Coloured icon for the event family (transaction / account / settings / team…). */
    public function tone(): string
    {
        return match (true) {
            str_ends_with($this->event, '.deleted'), str_ends_with($this->event, '.force_deleted') => 'rose',
            str_ends_with($this->event, '.created'), str_ends_with($this->event, '.restored') => 'emerald',
            str_ends_with($this->event, '.updated'), str_ends_with($this->event, '.moved') => 'amber',
            default => 'slate',
        };
    }
}
