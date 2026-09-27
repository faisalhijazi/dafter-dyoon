<?php

namespace App\Services;

use App\Models\Account;
use App\Models\PaymentPromise;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures for "الإحصائيات", "أعمار الديون" and the monthly report. All amounts are converted to
 * the base currency at current rates, like the rest of the dashboard.
 */
class ReportService
{
    public const AGING_BUCKETS = ['0-30' => '0–30 يوماً', '31-60' => '31–60 يوماً', '61-90' => '61–90 يوماً', '90+' => 'أكثر من 90 يوماً'];

    public function __construct(private LedgerService $ledger, private CurrencyConverter $converter) {}

    /**
     * New debt vs collections per month, plus the market position (what customers owe net) at each month end.
     *
     * @return list<array{month: string, label: string, debts: float, collections: float, position: float}>
     */
    public function monthlyFlows(int $months = 12): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);

        $rows = Transaction::query()
            ->where('occurred_at', '>=', $start)
            ->groupBy('ym', 'currency_id', 'type')
            ->select('currency_id', 'type', DB::raw("DATE_FORMAT(occurred_at, '%Y-%m') as ym"), DB::raw('SUM(amount) as total'))
            ->toBase()->get();

        // Signed balance of everything before the window (debit = customer owes us).
        $opening = $this->signedBase(Transaction::query()->where('occurred_at', '<', $start));

        $series = [];
        $position = $opening;

        for ($i = 0; $i < $months; $i++) {
            $month = $start->addMonths($i);
            $key = $month->format('Y-m');
            $inMonth = $rows->where('ym', $key);

            $debts = $inMonth->where('type', Transaction::DEBIT)->sum(fn ($r) => $this->converter->toBase((float) $r->total, (int) $r->currency_id));
            $credits = $inMonth->where('type', Transaction::CREDIT)->sum(fn ($r) => $this->converter->toBase((float) $r->total, (int) $r->currency_id));
            $position += $credits - $debts;

            $series[] = [
                'month' => $key,
                'label' => $month->translatedFormat('M'),
                'debts' => round($debts, 2),
                'collections' => round($credits, 2),
                'position' => round(-$position, 2) + 0.0, // > 0: the market owes us (+0.0 avoids "-0")
            ];
        }

        return $series;
    }

    /** Σ credit − Σ debit in base currency for a transaction query. */
    private function signedBase($query): float
    {
        return (clone $query)->groupBy('currency_id', 'type')
            ->select('currency_id', 'type', DB::raw('SUM(amount) as total'))
            ->toBase()->get()
            ->sum(fn ($r) => ($r->type === Transaction::CREDIT ? 1 : -1) * $this->converter->toBase((float) $r->total, (int) $r->currency_id));
    }

    /**
     * Accounts with the most movement (both directions) in the last `$days` days.
     *
     * @return Collection<int, array{account: Account, volume: float, count: int}>
     */
    public function topCustomers(int $days = 90, int $limit = 5): Collection
    {
        $rows = Transaction::query()
            ->where('occurred_at', '>=', now()->subDays($days))
            ->whereHas('account')
            ->groupBy('account_id', 'currency_id')
            ->select('account_id', 'currency_id', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as n'))
            ->toBase()->get()
            ->groupBy('account_id')
            ->map(fn ($g) => [
                'volume' => $g->sum(fn ($r) => $this->converter->toBase((float) $r->total, (int) $r->currency_id)),
                'count' => (int) $g->sum('n'),
            ])
            ->sortByDesc('volume')
            ->take($limit);

        $accounts = Account::query()->findMany($rows->keys())->keyBy('id');

        return $rows->map(fn ($r, $id) => ['account' => $accounts[$id], ...$r])->filter(fn ($r) => $r['account'])->values();
    }

    /**
     * Debt aging: what each debtor owes split by how old the unpaid debt is. Payments settle the
     * oldest debts first (FIFO), so the remainder shows how long money has really been outstanding.
     *
     * @return array{rows: Collection<int, array{account: Account, total: float, buckets: array<string, float>, oldest: ?CarbonImmutable}>, totals: array<string, float>, total: float}
     */
    public function aging(?int $categoryId = null): array
    {
        $accounts = Account::query()->with('category')
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->get()->keyBy('id');
        $balances = $this->ledger->balances($accounts->modelKeys());
        $today = CarbonImmutable::today();

        $debtorIds = collect($balances)->filter(fn ($b) => $this->converter->sumToBase($b) < -0.004)->keys();

        $transactions = Transaction::query()->whereIn('account_id', $debtorIds)
            ->orderBy('occurred_at')->orderBy('id')
            ->get(['account_id', 'currency_id', 'type', 'amount', 'occurred_at'])
            ->groupBy('account_id');

        $rows = $debtorIds->map(function ($id) use ($accounts, $transactions, $today) {
            $txs = $transactions->get($id, collect());
            $pool = $txs->where('type', Transaction::CREDIT)->sum(fn ($t) => $this->converter->toBase((float) $t->amount, $t->currency_id));
            $buckets = array_fill_keys(array_keys(self::AGING_BUCKETS), 0.0);
            $oldest = null;

            foreach ($txs->where('type', Transaction::DEBIT) as $debit) {
                $amount = $this->converter->toBase((float) $debit->amount, $debit->currency_id);
                $settled = min($pool, $amount);
                $pool -= $settled;
                $open = $amount - $settled;

                if ($open <= 0.004) {
                    continue;
                }

                $oldest ??= $debit->occurred_at;
                $age = (int) $debit->occurred_at->startOfDay()->diffInDays($today);
                $buckets[match (true) {
                    $age <= 30 => '0-30',
                    $age <= 60 => '31-60',
                    $age <= 90 => '61-90',
                    default => '90+',
                }] += $open;
            }

            return [
                'account' => $accounts[$id],
                'buckets' => array_map(fn ($v) => round($v, 2), $buckets),
                'total' => round(array_sum($buckets), 2),
                'oldest' => $oldest,
            ];
        })
            ->filter(fn ($r) => $r['total'] > 0.004)
            ->sortByDesc(fn ($r) => $r['buckets']['90+'] * 1e6 + $r['buckets']['61-90'] * 1e3 + $r['total'])
            ->values();

        $totals = [];
        foreach (array_keys(self::AGING_BUCKETS) as $key) {
            $totals[$key] = round($rows->sum(fn ($r) => $r['buckets'][$key]), 2);
        }

        return ['rows' => $rows, 'totals' => $totals, 'total' => round(array_sum($totals), 2)];
    }

    /**
     * Everything the monthly report shows for one month.
     *
     * @return array<string, mixed>
     */
    public function monthly(CarbonImmutable $month): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $previous = $start->subMonth();

        $flows = fn (CarbonImmutable $from, CarbonImmutable $to) => Transaction::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy('currency_id', 'type')
            ->select('currency_id', 'type', DB::raw('SUM(amount) as total'), DB::raw('COUNT(*) as n'))
            ->toBase()->get();

        $current = $flows($start, $end);
        $before = $flows($previous, $previous->endOfMonth());
        $sum = fn ($rows, string $type) => round($rows->where('type', $type)->sum(fn ($r) => $this->converter->toBase((float) $r->total, (int) $r->currency_id)), 2);

        $position = $this->ledger->balances(null, to: $end);
        $receivable = 0.0;
        $payable = 0.0;
        foreach ($position as $currencies) {
            $net = $this->converter->sumToBase($currencies);
            $net < 0 ? $receivable -= $net : $payable += $net;
        }

        $accountNames = Account::withTrashed()->pluck('name', 'id');
        $topDebtors = collect($position)
            ->map(fn ($c, $id) => ['name' => $accountNames[$id] ?? '—', 'owed' => -$this->converter->sumToBase($c)])
            ->filter(fn ($r) => $r['owed'] > 0.004)
            ->sortByDesc('owed')->take(5)->values();

        $promises = PaymentPromise::query()->whereBetween('promised_on', [$start->toDateString(), $end->toDateString()])
            ->groupBy('status')->select('status', DB::raw('COUNT(*) as n'))->toBase()->pluck('n', 'status');

        return [
            'month' => $start,
            'debts' => $sum($current, Transaction::DEBIT),
            'collections' => $sum($current, Transaction::CREDIT),
            'previous_debts' => $sum($before, Transaction::DEBIT),
            'previous_collections' => $sum($before, Transaction::CREDIT),
            'transactions' => (int) $current->sum('n'),
            'new_accounts' => Account::query()->whereBetween('created_at', [$start, $end])->count(),
            'receivable' => round($receivable, 2),
            'payable' => round($payable, 2),
            'top_debtors' => $topDebtors,
            'promises' => [
                'kept' => (int) ($promises[PaymentPromise::KEPT] ?? 0),
                'broken' => (int) ($promises[PaymentPromise::BROKEN] ?? 0),
                'pending' => (int) ($promises[PaymentPromise::PENDING] ?? 0),
            ],
        ];
    }
}
