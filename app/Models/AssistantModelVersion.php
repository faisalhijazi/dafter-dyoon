<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One retraining run of the assistant's intent model.
 *
 * @property int $id
 * @property string $path
 * @property string $status
 * @property int $template_samples
 * @property int $learned_samples
 * @property string $holdout_accuracy
 * @property int $acceptance_passed
 * @property int $acceptance_total
 * @property string|null $notes
 * @property string $trigger
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable $created_at
 */
class AssistantModelVersion extends Model
{
    public const ACTIVE = 'active';

    public const ARCHIVED = 'archived';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'path', 'status', 'template_samples', 'learned_samples', 'holdout_accuracy',
        'acceptance_passed', 'acceptance_total', 'notes', 'trigger', 'activated_at',
    ];

    protected function casts(): array
    {
        return ['holdout_accuracy' => 'decimal:4', 'activated_at' => 'datetime'];
    }

    public function passedGate(): bool
    {
        return $this->acceptance_total > 0 && $this->acceptance_passed === $this->acceptance_total;
    }

    public function fileExists(): bool
    {
        return is_file($this->path);
    }
}
