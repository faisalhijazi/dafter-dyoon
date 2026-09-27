<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A nickname the merchant uses for an account, learnt by «مساعد AI».
 *
 * @property int $id
 * @property int $account_id
 * @property string $alias
 * @property int $hits
 */
class AccountAlias extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'account_id', 'alias', 'hits'];

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
