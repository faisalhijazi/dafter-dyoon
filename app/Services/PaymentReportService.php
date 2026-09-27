<?php

namespace App\Services;

use App\Models\PaymentReport;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Payments customers reported from their statement link: approve (recorded as a credit with the
 * receipt attached) or reject.
 */
class PaymentReportService
{
    public function __construct(
        private LedgerService $ledger,
        private CurrencyConverter $converter,
        private ActivityLogger $logger,
    ) {}

    public function approve(PaymentReport $report): Transaction
    {
        if ($report->status !== PaymentReport::PENDING) {
            throw new RuntimeException('تمت مراجعة هذه الدفعة مسبقاً.');
        }

        $currency = $report->currency ?? $this->converter->base();

        if (! $report->account->tenant->canUseCurrency($currency)) {
            throw new RuntimeException("عملة الدفعة ({$currency->name}) غير متاحة في خطتك الحالية.");
        }

        return DB::transaction(function () use ($report, $currency) {
            $notes = trim('دفعة أبلغ عنها الزبون — '.$report->methodLabel().($report->reference ? ' #'.$report->reference : '').($report->notes ? ' — '.$report->notes : ''));

            $tx = $this->ledger->record($report->account, Transaction::CREDIT, (float) $report->amount, $currency, mb_substr($notes, 0, 1000), $report->created_at, $report->receipt_path);

            $report->update(['status' => PaymentReport::APPROVED, 'reviewed_at' => now(), 'transaction_id' => $tx->id]);

            return $tx;
        });
    }

    public function reject(PaymentReport $report): void
    {
        if ($report->status !== PaymentReport::PENDING) {
            return;
        }

        $report->update(['status' => PaymentReport::REJECTED, 'reviewed_at' => now()]);

        $currency = $report->currency ?? $this->converter->base();
        $this->logger->log('payment_report.rejected', 'رفض دفعة أبلغ عنها '.$report->account->name.': '.$currency->format($report->amount).' '.$currency->code, $report);
    }
}
