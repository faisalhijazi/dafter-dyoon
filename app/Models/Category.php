<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use BelongsToTenant;

    /** Palette keys available for categories (mapped to Tailwind classes in the UI). */
    public const COLORS = ['amber', 'cyan', 'pink', 'violet', 'emerald', 'rose', 'sky', 'orange', 'indigo', 'slate'];

    protected $fillable = ['tenant_id', 'name', 'color', 'is_default', 'sort_order'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'sort_order' => 'integer'];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
