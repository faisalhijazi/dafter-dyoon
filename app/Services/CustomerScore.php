<?php

namespace App\Services;

use App\Models\Account;
use App\Models\PaymentPromise;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "تقييم الزبون": a 0–100 payment-behaviour score per account, computed in a few grouped
 * queries so the dashboard can show it for a whole page of accounts.
 *
 * Starts at 100 and loses points for broken promises, long gaps without paying while in debt,
 * passed due dates and exceeding the debt ceiling; kept promises add a little back.
 */
class CustomerScore
{
    public const GRADES = [
        'excellent' => ['label' => 'ممتاز', 'min' => 85, 'color' => 'emerald'],
        'good' => ['label' => 'جيد', 'min' => 65, 'color' => 'sky'],
        'fair' => ['label' => 'متوسط', 'min' => 45, 'color' => 'amber'],
        'late' => ['label' => 'متأخر', 'min' => 0, 'color' => 'rose'],
    ];

    public function __construct(
        private LedgerService $ledger,
        private CurrencyConverter $converter,
        private DebtLimitService $limits,
    ) {}

    /**
     * @param  iterable<Account>  $accounts
     * @param  array<int, array<int, float>>|null  $balances  pre-computed LedgerService::balances()
     * @return array<int, array{score: int, grade: string, label: string, color: string, reasons: list<string>}|null>
     */
    public function forAccounts(iterable $accounts, ?array $balances = null): array
    {
        $accounts = collect($accounts);
        $ids = $accounts->pluck('id')->all();

        if (! $ids) {
            return [];
        }

        $balances ??= $this->ledger->balances($ids);
        $tenant = $accounts->first()->tenant;

        $promises = PaymentPromise::query()->whereIn('account_id', $ids)
            ->whereIn('status', [PaymentPromise::KEPT, PaymentPromise::BROKEN])
            ->groupBy('account_id', 'status')
            ->select('account_id', 'status', DB::raw('COUNT(*) as n'))
            ->toBase()->get()
            ->groupBy('account_id');

        $activity = Transaction::query()->whereIn('account_id', $ids)
            ->groupBy('account_id')
            ->select('account_id')
            ->selectRaw("MAX(CASE WHEN type = 'credit' THEN occurred_at END) as last_payment")
            ->selectRaw('MIN(occurred_at) as first_tx')
            ->toBase()->get()
            ->keyBy('account_id');

        $today = CarbonImmutable::today();
        $result = [];

        foreach ($accounts as $account) {
            $row = $activity->get($account->id);

            if (! $row) {
                $result[$account->id] = null; // no history yet: nothing to rate

                continue;
            }

            $balance = $this->converter->sumToBase($balances[$account->id] ?? []);
            $owes = $balance < -0.004;
            $counts = collect($promises->get($account->id, []))->pluck('n', 'status');
            $broken = (int) ($counts[PaymentPromise::BROKEN] ?? 0);
            $kept = (int) ($counts[PaymentPromise::KEPT] ?? 0);

            $score = 100;
            $reasons = [];

            if ($broken) {
                $score -= min(45, $broken * 15);
                $reasons[] = "أخلف {$broken} وعد سداد";
            }

            if ($kept) {
                $score += min(15, $kept * 5);
                $reasons[] = "التزم بـ {$kept} وعد سداد";
            }

            if ($owes) {
                $since = CarbonImmutable::parse($row->last_payment ?? $row->first_tx);
                $gap = (int) $since->diffInDays($today);

                if ($gap > 30) {
                    $score -= match (true) {
                        $gap > 90 => 45,
                        $gap > 60 => 30,
                        default => 15,
                    };
                    $reasons[] = $row->last_payment ? "لم يسدد منذ {$gap} يوماً" : "لم يسدد أي دفعة منذ {$gap} يوماً";
                }

                if ($account->due_date && CarbonImmutable::parse($account->due_date)->lt($today)) {
                    $weeks = (int) ceil(CarbonImmutable::parse($account->due_date)->diffInDays($today) / 7);
                    $score -= min(30, $weeks * 10);
                    $reasons[] = 'تجاوز موعد السداد';
                }

                if ($this->limits->status($account, $tenant, $balance)['state'] === 'exceeded') {
                    $score -= 15;
                    $reasons[] = 'تجاوز سقف الدين';
                }
            } else {
                $score = max($score, 85); // settled or in credit: at least "excellent"
            }

            $score = max(0, min(100, $score));
            $grade = collect(self::GRADES)->search(fn ($g) => $score >= $g['min']);

            $result[$account->id] = [
                'score' => $score,
                'grade' => $grade,
                'label' => self::GRADES[$grade]['label'],
                'color' => self::GRADES[$grade]['color'],
                'reasons' => $reasons,
            ];
        }

        return $result;
    }
}
