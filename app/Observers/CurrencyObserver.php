<?php

namespace App\Observers;

use App\Models\Currency;
use App\Services\ActivityLogger;

class CurrencyObserver
{
    public function __construct(private ActivityLogger $logger) {}

    public function created(Currency $currency): void
    {
        $this->logger->log('currency.created', "أضاف عملة {$currency->name}", $currency);
    }

    public function updated(Currency $currency): void
    {
        if ($currency->wasChanged('exchange_rate') && ! $currency->wasChanged('is_base')) {
            $this->logger->log('currency.updated', sprintf(
                'غيّر سعر صرف %s من %s إلى %s',
                $currency->name,
                (float) $currency->getOriginal('exchange_rate'),
                (float) $currency->exchange_rate,
            ), $currency);
        }

        if ($currency->wasChanged('is_base') && $currency->is_base) {
            $this->logger->log('currency.updated', "عيّن {$currency->name} عملةً أساسية", $currency);
        }
    }

    public function deleted(Currency $currency): void
    {
        $this->logger->log('currency.deleted', "حذف عملة {$currency->name}", $currency);
    }
}
