<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An objection a customer raised from their public statement page.
 */
class StatementDispute extends Model
{
    use BelongsToTenant;

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    protected $fillable = [
        'tenant_id', 'account_id', 'transaction_id', 'name', 'message', 'status', 'resolved_at', 'ip',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class)->withTrashed();
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
