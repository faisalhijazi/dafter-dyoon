<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Account extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'category_id', 'name', 'phone_code', 'phone', 'address',
        'notes', 'has_custom_limit', 'debt_limit', 'last_activity_at', 'pinned_at', 'due_date', 'due_repeat',
    ];

    /** How a due date moves forward once the customer pays. */
    public const DUE_REPEATS = ['none' => 'مرة واحدة', 'weekly' => 'كل أسبوع', 'monthly' => 'كل شهر'];

    protected function casts(): array
    {
        return [
            'has_custom_limit' => 'boolean',
            'debt_limit' => 'decimal:2',
            'last_activity_at' => 'datetime',
            'statement_viewed_at' => 'datetime',
            'pinned_at' => 'datetime',
            'due_date' => 'date',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** @return HasMany<PaymentPromise, $this> */
    public function promises(): HasMany
    {
        return $this->hasMany(PaymentPromise::class);
    }

    /** @return HasMany<PaymentReport, $this> */
    public function paymentReports(): HasMany
    {
        return $this->hasMany(PaymentReport::class);
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(StatementDispute::class);
    }

    public function hasStatementLink(): bool
    {
        return $this->statement_token !== null;
    }

    /** Turn the public statement link on, or replace it so the old link stops working. */
    public function regenerateStatementToken(): void
    {
        $this->forceFill(['statement_token' => Str::random(48)])->save();
    }

    public function disableStatementLink(): void
    {
        $this->forceFill(['statement_token' => null])->save();
    }

    public function statementUrl(): ?string
    {
        return $this->statement_token ? route('statement.show', $this->statement_token) : null;
    }

    public function initial(): string
    {
        return Str::substr(trim($this->name), 0, 1);
    }

    /** International number without "+" or leading zeros, as wa.me expects. */
    public function whatsappNumber(): ?string
    {
        if (! $this->phone) {
            return null;
        }

        $code = preg_replace('/\D/', '', (string) $this->phone_code);
        $phone = ltrim(preg_replace('/\D/', '', $this->phone), '0');

        return $code.$phone;
    }
}
