<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A free label on accounts ("جملة", "مفرق", "قريب"…) used for filtering.
 *
 * @property int $id
 * @property string $name
 * @property string $color
 */
class Tag extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'name', 'color'];

    /** @return BelongsToMany<Account, $this> */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class);
    }
}
