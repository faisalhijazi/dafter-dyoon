<?php

namespace App\Services;

use App\Models\Currency;
use App\Support\TenantContext;
use Illuminate\Support\Collection;

/**
 * Converts amounts between a tenant's currencies.
 *
 * Every currency stores `exchange_rate` = how many base-currency units equal one
 * unit of it (e.g. 1 USD = 3.7 ILS when ILS is the base), so the base currency's
 * rate is always 1.
 */
class CurrencyConverter
{
    /** @var array<int, Collection<int, Currency>> keyed by tenant id */
    private array $cache = [];

    public function __construct(private TenantContext $context) {}

    /** @return Collection<int, Currency> keyed by id */
    public function currencies(): Collection
    {
        $tenantId = (int) $this->context->id();

        return $this->cache[$tenantId] ??= Currency::orderByDesc('is_base')->orderBy('sort_order')->get()->keyBy('id');
    }

    /**
     * Currencies the tenant's plan lets it record in (free plan: base + shekel + dollar).
     *
     * @return Collection<int, Currency> list, base first
     */
    public function usable(): Collection
    {
        $tenant = $this->context->tenant();

        return $this->currencies()
            ->filter(fn (Currency $c) => ! $tenant || $tenant->canUseCurrency($c))
            ->values();
    }

    /**
     * Currencies locked behind a paid plan, for "upgrade to use …" hints.
     *
     * @return Collection<int, Currency>
     */
    public function locked(): Collection
    {
        $usable = $this->usable()->modelKeys();

        return $this->currencies()->reject(fn (Currency $c) => in_array($c->id, $usable, true))->values();
    }

    public function base(): ?Currency
    {
        return $this->currencies()->firstWhere('is_base', true) ?? $this->currencies()->first();
    }

    public function find(int $id): ?Currency
    {
        return $this->currencies()->get($id);
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    public function toBase(float $amount, Currency|int $currency): float
    {
        $currency = $currency instanceof Currency ? $currency : $this->find($currency);

        return $currency ? $amount * (float) $currency->exchange_rate : $amount;
    }

    public function fromBase(float $amount, Currency $currency): float
    {
        $rate = (float) $currency->exchange_rate;

        return $rate > 0 ? $amount / $rate : 0.0;
    }

    public function convert(float $amount, Currency $from, Currency $to): float
    {
        if ($from->is($to)) {
            return $amount;
        }

        return $this->fromBase($this->toBase($amount, $from), $to);
    }

    /**
     * Sum a [currency_id => signed amount] map into the base currency using current rates.
     *
     * @param  array<int, float>  $balances
     */
    public function sumToBase(array $balances): float
    {
        $total = 0.0;

        foreach ($balances as $currencyId => $amount) {
            $total += $this->toBase((float) $amount, (int) $currencyId);
        }

        return round($total, 4);
    }

    /**
     * Make a currency the base: rebase every other rate so relative values are preserved.
     */
    public function makeBase(Currency $newBase): void
    {
        $factor = (float) $newBase->exchange_rate;

        if ($factor <= 0 || $newBase->is_base) {
            return;
        }

        foreach (Currency::all() as $currency) {
            $currency->update([
                'exchange_rate' => $currency->is($newBase) ? 1 : (float) $currency->exchange_rate / $factor,
                'is_base' => $currency->is($newBase),
            ]);
        }

        $this->flush();
    }
}
