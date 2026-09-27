<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment the customer reported from their statement link (bank transfer, wallet…).
 * The merchant approves it (recorded as a credit) or rejects it.
 *
 * @property int $id
 * @property int $account_id
 * @property int|null $currency_id
 * @property string $amount
 * @property string $method
 * @property string|null $reference
 * @property string|null $payer_name
 * @property string|null $notes
 * @property string|null $receipt_path
 * @property string $status
 * @property CarbonImmutable $created_at
 * @property-read Account $account
 * @property-read Currency|null $currency
 */
class PaymentReport extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const METHODS = [
        'bank' => 'تحويل بنكي',
        'wallet' => 'محفظة إلكترونية',
        'cash' => 'نقداً لأحد العاملين',
        'other' => 'طريقة أخرى',
    ];

    protected $fillable = [
        'tenant_id', 'account_id', 'currency_id', 'transaction_id', 'amount', 'method', 'reference',
        'payer_name', 'notes', 'receipt_path', 'status', 'reviewed_at', 'ip',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'reviewed_at' => 'datetime'];
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

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }
}
