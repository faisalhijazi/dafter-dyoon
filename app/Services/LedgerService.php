<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Currency;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Balance maths and ledger writes.
 *
 * Sign convention: a credit ("له") is +amount and a debit ("عليه") is -amount.
 * A positive account balance means we owe the account ("له" → counts toward
 * "عليك"); a negative one means the account owes us ("عليه" → counts toward "لك").
 */
class LedgerService
{
    public function __construct(private CurrencyConverter $converter) {}

    /**
     * Signed balances grouped by account then currency, in one query.
     *
     * @param  array<int>|null  $accountIds  null = every account of the tenant
     * @return array<int, array<int, float>> [account_id => [currency_id => balance]]
     */
    public function balances(?array $accountIds = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $currencyId = null): array
    {
        $rows = Transaction::query()
            ->when($accountIds !== null, fn ($q) => $q->whereIn('account_id', $accountIds))
            ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from->startOfDay()))
            ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to->endOfDay()))
            ->when($currencyId, fn ($q) => $q->where('currency_id', $currencyId))
            ->whereHas('account')
            ->groupBy('account_id', 'currency_id')
            ->select('account_id', 'currency_id')
            ->selectRaw("SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END) AS balance")
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[$row->account_id][$row->currency_id] = round((float) $row->balance, 4);
        }

        return $result;
    }

    /** @return array<int, float> [currency_id => balance] */
    public function accountBalances(Account $account): array
    {
        return $this->balances([$account->id])[$account->id] ?? [];
    }

    public function accountBaseBalance(Account $account): float
    {
        return $this->converter->sumToBase($this->accountBalances($account));
    }

    /**
     * Totals for the floating bar, in the base currency.
     * Each account is netted across its currencies first, then split by sign.
     *
     * @param  array<int>|null  $accountIds
     * @return array{receivable: float, payable: float, net: float, accounts: int}
     */
    public function summary(?array $accountIds = null): array
    {
        $receivable = 0.0; // لك  — accounts that owe us
        $payable = 0.0;    // عليك — accounts we owe

        foreach ($this->balances($accountIds) as $currencies) {
            $net = $this->converter->sumToBase($currencies);

            if ($net < 0) {
                $receivable += -$net;
            } else {
                $payable += $net;
            }
        }

        return [
            'receivable' => round($receivable, 2),
            'payable' => round($payable, 2),
            'net' => round($receivable - $payable, 2), // > 0 لك, < 0 عليك
            'accounts' => $accountIds !== null ? count($accountIds) : Account::count(),
        ];
    }

    /**
     * Transactions for one account, newest first, each with the running balance of
     * its own currency after that transaction (`running_balance`).
     *
     * @return Collection<int, Transaction>
     */
    public function statement(Account $account, ?int $currencyId = null, ?string $search = null): Collection
    {
        $transactions = $account->transactions()
            ->with('currency')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $running = [];

        foreach ($transactions as $transaction) {
            $key = $transaction->currency_id;
            $running[$key] = ($running[$key] ?? 0) + $transaction->signedAmount();
            $transaction->running_balance = round($running[$key], 4);
        }

        return $transactions
            ->when($currencyId, fn ($c) => $c->where('currency_id', $currencyId))
            ->when($search, fn ($c) => $c->filter(fn (Transaction $t) => str_contains((string) $t->notes, $search)
                || str_contains((string) (float) $t->amount, $search)))
            ->reverse()
            ->values();
    }

    public function record(
        Account $account,
        string $type,
        float $amount,
        Currency $currency,
        ?string $notes = null,
        ?CarbonInterface $occurredAt = null,
        ?string $attachmentPath = null,
        string $kind = 'entry',
        ?string $groupUuid = null,
        ?string $clientUuid = null,
    ): Transaction {
        $transaction = $account->transactions()->create([
            'currency_id' => $currency->id,
            'user_id' => Auth::guard('web')->id(),
            'type' => $type,
            'amount' => round(abs($amount), 4),
            'exchange_rate' => $currency->exchange_rate,
            'kind' => $kind,
            'group_uuid' => $groupUuid,
            'client_uuid' => $clientUuid,
            'notes' => $notes,
            'attachment_path' => $attachmentPath,
            'occurred_at' => $occurredAt ?? now(),
        ]);

        $this->touchActivity($account);

        return $transaction;
    }

    /**
     * Currency exchange with an account.
     * Buy: the account hands us `amount` of `$currency` (له) and receives the base equivalent (عليه).
     * Sell: the reverse.
     *
     * @return Collection<int, Transaction>
     */
    public function exchange(Account $account, string $direction, Currency $currency, float $amount, float $rate, ?string $notes = null, ?CarbonInterface $at = null, ?string $attachment = null): Collection
    {
        $base = $this->converter->base();
        $group = (string) Str::uuid();
        $counter = round($amount * $rate, $base->decimal_places);
        $label = trim(($direction === 'buy' ? 'شراء ' : 'بيع ').$currency->format($amount).' '.$currency->code.' بسعر '.$rate.' '.$notes);

        return DB::transaction(fn () => collect([
            $this->record($account, $direction === 'buy' ? Transaction::CREDIT : Transaction::DEBIT, $amount, $currency, $label, $at, $attachment, 'exchange', $group),
            $this->record($account, $direction === 'buy' ? Transaction::DEBIT : Transaction::CREDIT, $counter, $base, $label, $at, null, 'exchange', $group),
        ]));
    }

    /**
     * Move an amount from one account to another: the source is debited (عليه)
     * and the destination credited (له) with the same amount and currency.
     *
     * @return Collection<int, Transaction>
     */
    public function transfer(Account $from, Account $to, float $amount, Currency $currency, ?string $notes = null, ?CarbonInterface $at = null): Collection
    {
        $group = (string) Str::uuid();

        return DB::transaction(fn () => collect([
            $this->record($from, Transaction::DEBIT, $amount, $currency, trim('تحويل إلى '.$to->name.' — '.$notes, ' —'), $at, null, 'transfer', $group),
            $this->record($to, Transaction::CREDIT, $amount, $currency, trim('تحويل من '.$from->name.' — '.$notes, ' —'), $at, null, 'transfer', $group),
        ]));
    }

    /**
     * Split a bill: each share becomes a separate entry of the same type on its account.
     *
     * @param  array<int, float>  $shares  [account_id => amount]
     * @return Collection<int, Transaction>
     */
    public function split(array $shares, string $type, Currency $currency, ?string $notes = null, ?CarbonInterface $at = null): Collection
    {
        $group = (string) Str::uuid();
        $accounts = Account::findMany(array_keys($shares))->keyBy('id');

        return DB::transaction(fn () => collect($shares)
            ->filter(fn ($amount, $id) => $amount > 0 && $accounts->has($id))
            ->map(fn ($amount, $id) => $this->record($accounts[$id], $type, $amount, $currency, $notes, $at, null, 'split', $group))
            ->values());
    }

    /**
     * Move transactions (and their linked legs stay where they are) to another account.
     *
     * @param  array<int>  $transactionIds
     */
    public function move(array $transactionIds, Account $target): int
    {
        $transactions = Transaction::whereIn('id', $transactionIds)->get();
        $sources = $transactions->pluck('account_id')->unique();

        Transaction::whereIn('id', $transactions->pluck('id'))->update(['account_id' => $target->id]);

        // A bulk update fires no model events, so log the move explicitly.
        $from = Account::withTrashed()->whereIn('id', $sources)->pluck('name')->implode('، ');
        app(ActivityLogger::class)->log('transaction.moved', "نقل {$transactions->count()} معاملة من {$from} إلى {$target->name}", $target, [
            'transaction_ids' => $transactions->modelKeys(),
        ]);

        Account::whereIn('id', $sources)->get()->each(fn (Account $a) => $this->touchActivity($a));
        $this->touchActivity($target);

        return $transactions->count();
    }

    public function touchActivity(Account $account): void
    {
        $latest = $account->transactions()->max('occurred_at');
        $account->forceFill(['last_activity_at' => $latest])->saveQuietly();
    }
}
