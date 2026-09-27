<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "بدفع الخميس": a customer's promise to pay. Kept automatically when a payment arrives by
 * the promised day; broken once the day passes without one.
 *
 * @property int $id
 * @property int $account_id
 * @property int|null $currency_id
 * @property string|null $amount
 * @property CarbonImmutable $promised_on
 * @property string $status
 * @property string|null $notes
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $created_at
 * @property-read Account $account
 * @property-read Currency|null $currency
 */
class PaymentPromise extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const KEPT = 'kept';

    public const BROKEN = 'broken';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['tenant_id', 'account_id', 'currency_id', 'user_id', 'amount', 'promised_on', 'status', 'resolved_at', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'promised_on' => 'date', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** @param  Builder<PaymentPromise>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }

    public function describe(): string
    {
        $amount = $this->amount !== null && $this->currency
            ? $this->currency->format($this->amount).' '.$this->currency->code
            : 'دفعة';

        return $amount.' بتاريخ '.$this->promised_on->format('Y/m/d');
    }
}
