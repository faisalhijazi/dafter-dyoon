<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    public const PAID = 'paid';

    public const FAILED = 'failed';

    protected $fillable = ['tenant_id', 'stripe_invoice_id', 'amount', 'currency', 'status', 'hosted_invoice_url', 'period_end'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'period_end' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function formattedAmount(): string
    {
        return strtoupper($this->currency).' '.rtrim(rtrim(number_format($this->amount / 100, 2, '.', ''), '0'), '.');
    }
}
