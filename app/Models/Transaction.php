<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $account_id
 * @property int $currency_id
 * @property string $type
 * @property string $amount
 * @property string|null $notes
 * @property CarbonImmutable $occurred_at
 * @property-read Currency $currency
 * @property-read Account $account
 */
class Transaction extends Model
{
    use BelongsToTenant, SoftDeletes;

    /** "له": the account gave us / we owe them. */
    public const CREDIT = 'credit';

    /** "عليه": the account took from us / they owe us. */
    public const DEBIT = 'debit';

    protected $fillable = [
        'tenant_id', 'account_id', 'currency_id', 'user_id', 'type', 'amount',
        'exchange_rate', 'kind', 'group_uuid', 'client_uuid', 'notes', 'attachment_path', 'occurred_at', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'occurred_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCredit(): bool
    {
        return $this->type === self::CREDIT;
    }

    /** Signed amount: credit (له) positive, debit (عليه) negative. */
    public function signedAmount(): float
    {
        return $this->isCredit() ? (float) $this->amount : -(float) $this->amount;
    }
}
