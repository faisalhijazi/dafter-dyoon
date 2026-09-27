<?php

namespace App\Observers;

use App\Models\Account;
use App\Services\ActivityLogger;

class AccountObserver
{
    /** Changes worth an entry in the activity log (not housekeeping columns). */
    private const LABELS = [
        'name' => 'الاسم',
        'phone' => 'الهاتف',
        'address' => 'العنوان',
        'notes' => 'الملاحظات',
        'category_id' => 'القسم',
        'debt_limit' => 'سقف الدين',
        'has_custom_limit' => 'استثناء السقف',
        'due_date' => 'موعد السداد',
        'due_repeat' => 'تكرار موعد السداد',
        'statement_token' => 'رابط الكشف الحي',
    ];

    public function __construct(private ActivityLogger $logger) {}

    public function created(Account $account): void
    {
        $this->logger->log('account.created', "أضاف حساب {$account->name}", $account);
    }

    public function updated(Account $account): void
    {
        $changed = array_intersect_key($account->getChanges(), self::LABELS);

        if (! $changed) {
            return;
        }

        $fields = implode('، ', array_map(fn ($key) => self::LABELS[$key], array_keys($changed)));

        $this->logger->log('account.updated', "عدّل حساب {$account->name}: {$fields}", $account, [
            'before' => array_intersect_key($account->getOriginal(), $changed),
            'after' => array_diff_key($changed, ['statement_token' => true]),
        ]);
    }

    public function deleted(Account $account): void
    {
        $verb = $account->isForceDeleting() ? 'حذف نهائياً' : 'حذف';
        $this->logger->log($account->isForceDeleting() ? 'account.force_deleted' : 'account.deleted', "{$verb} حساب {$account->name}", $account);
    }

    public function restored(Account $account): void
    {
        $this->logger->log('account.restored', "استرجع حساب {$account->name}", $account);
    }
}
