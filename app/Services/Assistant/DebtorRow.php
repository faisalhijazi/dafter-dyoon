<?php

namespace App\Services\Assistant;

use App\Models\Account;
use Carbon\CarbonImmutable;

/**
 * An account that owes the shop, as used by the assistant's reports.
 */
final class DebtorRow
{
    /** Last payment received (or the first debt if they never paid) — overdue report only. */
    public ?CarbonImmutable $since = null;

    /**
     * DebtLimitService::status() result — threshold report only.
     *
     * @var array{state: string, limit: float|null, owed: float, ratio: float}|null
     */
    public ?array $status = null;

    public function __construct(
        public readonly Account $account,
        public readonly float $owed,
    ) {}
}
