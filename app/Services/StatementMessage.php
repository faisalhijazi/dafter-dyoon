<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;

/**
 * Builds the plain-text account statement shared with a customer (WhatsApp, SMS, Telegram…).
 *
 * Like WhatsAppMessage it is written from the shop to the customer, so a credit ("له")
 * reads "لكم" and a debit ("عليه") reads "عليكم".
 */
class StatementMessage
{
    /** How many recent movements the text summary lists. */
    public const RECENT = 5;

    public function __construct(private LedgerService $ledger, private CurrencyConverter $converter) {}

    public function text(Account $account, bool $withLink = true): string
    {
        $line = '━━━━━━━━━━━━━━━━━━';
        $dash = '┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄';

        $lines = [
            $line,
            '📒 *'.$account->tenant->name.'*',
            $line,
            '',
            '📄 كشف حساب: *'.$account->name.'*',
            '📅 حتى تاريخ: '.now()->format('Y/m/d h:i A'),
            '',
            '💰 *الرصيد:*',
        ];

        $balances = array_filter($this->ledger->accountBalances($account), fn ($b) => round($b, 4) != 0.0);

        foreach ($balances as $currencyId => $balance) {
            $currency = $this->converter->find($currencyId);
            $lines[] = '  '.$this->dot($balance).' '.$currency->format(abs($balance)).' '.$currency->code.' '.$this->status($balance);
        }

        if (! $balances) {
            $lines[] = '  ⚪ الحساب متزن — لا يوجد رصيد مستحق';
        }

        if (count($balances) > 1 && ($base = $this->converter->base())) {
            $total = $this->converter->sumToBase($balances);
            $lines[] = '  📊 الإجمالي: '.$base->format(abs($total)).' '.$base->code.' '.$this->status($total);
        }

        $recent = $account->transactions()->with('currency')->latest('occurred_at')->latest('id')->take(self::RECENT)->get();

        if ($recent->isNotEmpty()) {
            array_push($lines, '', '🧾 *آخر الحركات:*');

            foreach ($recent as $tx) {
                $lines[] = '• '.$tx->occurred_at->format('Y/m/d').' — '.$tx->currency->format($tx->amount).' '.$tx->currency->code.' '
                    .$this->movement($tx).(filled($tx->notes) ? ' ('.$tx->notes.')' : '');
            }
        }

        if ($withLink && $account->hasStatementLink()) {
            array_push(
                $lines,
                '',
                $dash,
                '🔗 كشف الحساب المفصل (يُحدَّث لحظياً):',
                $account->statementUrl(),
                'يمكنك من خلاله الاعتراض على أي حركة.',
                $dash,
            );
        }

        return implode("\n", $lines);
    }

    private function movement(Transaction $tx): string
    {
        return $tx->isCredit() ? 'لكم' : 'عليكم';
    }

    private function status(float $balance): string
    {
        return match (true) {
            round($balance, 2) == 0.0 => 'متزن',
            $balance > 0 => 'لكم',
            default => 'عليكم',
        };
    }

    private function dot(float $balance): string
    {
        return match ($this->status($balance)) {
            'لكم' => '🟢',
            'عليكم' => '🔴',
            default => '⚪',
        };
    }
}
