<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Tenant;

/**
 * Debt ceilings ("سقوف التنبيه"): how much an account may owe us, in base currency.
 *
 * The tenant's `default_debt_limit` is the general rule (null = open account) and
 * an account with `has_custom_limit` overrides it (its own null = open for it).
 */
class DebtLimitService
{
    /** Share of the limit at which a warning badge is shown. */
    public const WARNING_RATIO = 0.8;

    public function limitFor(Account $account, Tenant $tenant): ?float
    {
        if (! $tenant->canUse('debt_limits')) {
            return null;
        }

        $limit = $account->has_custom_limit ? $account->debt_limit : $tenant->default_debt_limit;

        return $limit === null ? null : (float) $limit;
    }

    /**
     * @param  float  $baseBalance  signed balance in base currency (negative = the account owes us)
     * @return array{state: string, limit: float|null, owed: float, ratio: float}
     *                                                                            state: none | ok | warning | exceeded
     */
    public function status(Account $account, Tenant $tenant, float $baseBalance): array
    {
        $limit = $this->limitFor($account, $tenant);
        $owed = max(0, -$baseBalance);

        if ($limit === null) {
            return ['state' => 'none', 'limit' => null, 'owed' => $owed, 'ratio' => 0.0];
        }

        $ratio = $limit > 0 ? $owed / $limit : ($owed > 0 ? INF : 0.0);

        $state = match (true) {
            $owed > $limit => 'exceeded',
            $ratio >= self::WARNING_RATIO => 'warning',
            default => 'ok',
        };

        return ['state' => $state, 'limit' => $limit, 'owed' => $owed, 'ratio' => min($ratio, 9.99)];
    }

    /**
     * Would recording an extra debit of `$extraBase` push the account over its limit?
     */
    public function wouldExceed(Account $account, Tenant $tenant, float $baseBalance, float $extraBase): bool
    {
        $limit = $this->limitFor($account, $tenant);

        return $limit !== null && max(0, -($baseBalance - $extraBase)) > $limit;
    }
}
