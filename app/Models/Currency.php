<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'code', 'iso_code', 'name', 'exchange_rate', 'decimal_places', 'is_base', 'sort_order'];

    protected function casts(): array
    {
        return [
            'exchange_rate' => 'decimal:6',
            'decimal_places' => 'integer',
            'is_base' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function format(float|string $amount): string
    {
        return number_format((float) $amount, $this->decimal_places);
    }
}
