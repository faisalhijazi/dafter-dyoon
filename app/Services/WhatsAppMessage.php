<?php

namespace App\Services;

use App\Models\Transaction;

/**
 * Builds the formatted WhatsApp message and wa.me link for a transaction.
 */
class WhatsAppMessage
{
    public function __construct(private LedgerService $ledger, private CurrencyConverter $converter) {}

    public function forTransaction(Transaction $transaction): string
    {
        $account = $transaction->account;
        $currency = $transaction->currency;
        $base = $this->converter->base();
        $total = $this->ledger->accountBaseBalance($account);

        // Written from the shop to the customer: a credit ("له") is "لكم", a debit ("عليه") is "عليكم".
        $type = $transaction->isCredit() ? 'لكم' : 'عليكم';
        $status = match (true) {
            round($total, 2) == 0.0 => 'متزن',
            $total > 0 => 'لكم',
            default => 'عليكم',
        };
        $dot = match ($status) {
            'لكم' => '🟢',
            'عليكم' => '🔴',
            default => '⚪',
        };

        $line = '━━━━━━━━━━━━━━━━━━';
        $dash = '┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄┄';

        $lines = [
            $line,
            '📒 *'.(config('app.name')).'*',
            $line,
            '',
            '📝 معاملة مالية',
            '',
            '💰 المبلغ: *'.$currency->format($transaction->amount).' '.$currency->code.'* '.$type,
            '📅 التاريخ: '.$transaction->occurred_at->format('Y/m/d h:i A'),
        ];

        if (filled($transaction->notes)) {
            array_push($lines, '', '📌 الملاحظات: '.$transaction->notes);
        }

        array_push(
            $lines,
            '',
            $dash,
            '📊 *الرصيد الإجمالي:*',
            '  '.$dot.' '.$base->format(abs($total)).' '.$base->code.' '.$status,
            $dash,
        );

        // Let the customer confirm (or dispute) this movement from their live statement.
        if ($account->hasStatementLink()) {
            array_push($lines, '', '✅ لتأكيد الحركة أو الاعتراض عليها:', $account->statementUrl().'#tx-'.$transaction->id);
        }

        return implode("\n", $lines);
    }

    public function link(Transaction $transaction): string
    {
        $number = $transaction->account->whatsappNumber();

        return 'https://wa.me/'.($number ?? '').'?text='.rawurlencode($this->forTransaction($transaction));
    }
}
