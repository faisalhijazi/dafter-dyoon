<?php

namespace App\Services;

use App\Models\Account;
use App\Models\PaymentPromise;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Debt collection: due dates ("موعد السداد"), payment promises ("بدفع الخميس") and reminders.
 */
class CollectionsService
{
    /** Due dates this many days ahead appear as "قريباً". */
    public const UPCOMING_DAYS = 7;

    public function __construct(private LedgerService $ledger, private CurrencyConverter $converter) {}

    /**
     * A payment (credit) was recorded: keep matching promises and move the due date forward.
     */
    public function onPayment(Transaction $payment): void
    {
        $account = $payment->account;

        if (! $account) {
            return;
        }

        $this->resolvePromises($account, $payment);
        $this->advanceDueDate($account);
    }

    private function resolvePromises(Account $account, Transaction $payment): void
    {
        $paidOn = $payment->occurred_at->toDateString();

        foreach ($account->promises()->pending()->orderBy('promised_on')->get() as $promise) {
            if ($paidOn > $promise->promised_on->toDateString()) {
                continue; // paid after the promised day: that promise is broken, not kept
            }

            if ($promise->amount === null || $this->paidSince($account, $promise) >= (float) $promise->amount * 0.99) {
                $promise->update(['status' => PaymentPromise::KEPT, 'resolved_at' => now()]);
            }
        }
    }

    /** Payments since the promise was made, in the promise's currency. */
    private function paidSince(Account $account, PaymentPromise $promise): float
    {
        $currency = $promise->currency ?? $this->converter->base();

        return $account->transactions()
            ->where('type', Transaction::CREDIT)
            ->where('occurred_at', '>=', $promise->created_at->startOfDay())
            ->get(['amount', 'currency_id'])
            ->sum(fn (Transaction $t) => $t->currency_id === $currency->id
                ? (float) $t->amount
                : $this->converter->fromBase($this->converter->toBase((float) $t->amount, $t->currency_id), $currency));
    }

    /** Repeating due dates roll to the next period; a one-off one clears once the account is settled. */
    public function advanceDueDate(Account $account): void
    {
        if (! $account->due_date) {
            return;
        }

        $today = CarbonImmutable::today();
        $due = CarbonImmutable::parse($account->due_date);

        if ($account->due_repeat === 'none') {
            if ($this->ledger->accountBaseBalance($account) >= -0.004) {
                $account->forceFill(['due_date' => null])->saveQuietly();
            }

            return;
        }

        while ($due->lte($today)) {
            $due = $account->due_repeat === 'weekly' ? $due->addWeek() : $due->addMonthNoOverflow();
        }

        $account->forceFill(['due_date' => $due])->saveQuietly();
    }

    /** Pending promises whose day passed without enough payment become broken. */
    public function breakOverduePromises(): int
    {
        return PaymentPromise::query()->pending()
            ->where('promised_on', '<', CarbonImmutable::today()->toDateString())
            ->update(['status' => PaymentPromise::BROKEN, 'resolved_at' => now()]);
    }

    /**
     * Accounts that owe us with a due date that passed or comes within UPCOMING_DAYS.
     *
     * @return Collection<int, array{account: Account, owed: float, due: CarbonImmutable, state: string, days: int}>
     */
    public function dueAccounts(): Collection
    {
        $today = CarbonImmutable::today();
        $accounts = Account::query()->with('category')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', $today->addDays(self::UPCOMING_DAYS)->toDateString())
            ->orderBy('due_date')
            ->get();

        $balances = $this->ledger->balances($accounts->modelKeys());

        return $accounts
            ->map(function (Account $account) use ($balances, $today) {
                $due = CarbonImmutable::parse($account->due_date);
                $days = (int) $today->diffInDays($due, false);

                return [
                    'account' => $account,
                    'owed' => -$this->converter->sumToBase($balances[$account->id] ?? []),
                    'due' => $due,
                    'days' => $days,
                    'state' => $days < 0 ? 'overdue' : ($days === 0 ? 'today' : 'soon'),
                ];
            })
            ->filter(fn ($row) => $row['owed'] > 0.004)
            ->values();
    }

    /** Friendly WhatsApp reminder, with the live statement link when the account has one. */
    public function reminderText(Account $account, float $owed, ?PaymentPromise $promise = null): string
    {
        $base = $this->converter->base();
        $lines = [
            'السلام عليكم '.$account->name.' 🌷',
            '',
            $promise
                ? 'تذكير لطيف بموعد الدفعة التي اتفقنا عليها: '.$promise->describe().'.'
                : 'تذكير لطيف بموعد السداد'.($account->due_date ? ' ('.CarbonImmutable::parse($account->due_date)->format('Y/m/d').')' : '').'.',
            '💰 الرصيد المستحق: *'.$base->format($owed).' '.$base->code.'*',
        ];

        if ($url = $account->statementUrl()) {
            array_push($lines, '', '📄 كشف حسابك المفصل:', $url);
        }

        array_push($lines, '', 'شكراً لتعاملك مع '.$account->tenant->name.' 🙏');

        return implode("\n", $lines);
    }

    public function reminderLink(Account $account, float $owed, ?PaymentPromise $promise = null): string
    {
        return 'https://wa.me/'.($account->whatsappNumber() ?? '').'?text='.rawurlencode($this->reminderText($account, $owed, $promise));
    }
}
