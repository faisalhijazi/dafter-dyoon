<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Services\ActivityLogger;
use App\Services\CollectionsService;

/**
 * Every ledger movement, whatever created it (dashboard, assistant, offline sync, a customer's
 * approved payment report), is logged and feeds the collections engine.
 */
class TransactionObserver
{
    private const TRACKED = ['type', 'amount', 'currency_id', 'notes', 'occurred_at', 'account_id'];

    public function __construct(private ActivityLogger $logger, private CollectionsService $collections) {}

    public function created(Transaction $tx): void
    {
        $this->logger->log('transaction.created', 'أضاف '.$this->describe($tx), $tx);

        if ($tx->isCredit()) {
            $this->collections->onPayment($tx);
        }
    }

    public function updated(Transaction $tx): void
    {
        $changes = collect($tx->getChanges())->only(self::TRACKED);

        if ($changes->isEmpty()) {
            return; // e.g. only confirmed_at changed — logged by the confirming code
        }

        $before = collect($tx->getOriginal())->only($changes->keys())->all();
        $this->logger->log('transaction.updated', 'عدّل '.$this->describe($tx), $tx, [
            'before' => $before,
            'after' => $changes->all(),
        ]);
    }

    public function deleted(Transaction $tx): void
    {
        $event = $tx->isForceDeleting() ? 'transaction.force_deleted' : 'transaction.deleted';
        $verb = $tx->isForceDeleting() ? 'حذف نهائياً ' : 'حذف ';

        $this->logger->log($event, $verb.$this->describe($tx), $tx);
    }

    public function restored(Transaction $tx): void
    {
        $this->logger->log('transaction.restored', 'استرجع '.$this->describe($tx), $tx);
    }

    private function describe(Transaction $tx): string
    {
        $currency = $tx->currency;
        $amount = $currency ? $currency->format($tx->amount).' '.$currency->code : (string) (float) $tx->amount;

        return 'عملية '.($tx->isCredit() ? 'له' : 'عليه').' '.$amount.' لحساب '.($tx->account?->name ?? '—')
            .(filled($tx->notes) ? ' ('.$tx->notes.')' : '');
    }
}
