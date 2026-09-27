<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Subscription billing on Stripe without the SDK: Checkout to subscribe, the Customer Portal to
 * change card / cancel, and webhooks (plus a daily re-sync) to keep `tenants.subscription_ends_at`
 * in step with Stripe. Everything downstream (plan limits, Pro features) keeps reading
 * Tenant::effectivePlan(), so an expired or cancelled subscription falls back to the free plan.
 */
class StripeBilling
{
    private const API = 'https://api.stripe.com/v1/';

    /** Days of Pro kept after the paid period ends, so a late renewal webhook never downgrades anyone. */
    public const GRACE_DAYS = 2;

    public const INTERVALS = ['month' => 'شهري', 'year' => 'سنوي'];

    /** Subscription states that keep the plan (past_due: Stripe is still retrying the card). */
    private const LIVE = ['active', 'trialing', 'past_due'];

    /** States after which the subscription will never bill again. */
    private const ENDED = ['canceled', 'unpaid', 'incomplete_expired', 'paused'];

    public function isConfigured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    /**
     * Start a Checkout session for a paid plan and return its URL.
     */
    public function checkout(Tenant $tenant, Plan $plan, string $interval, User $user): string
    {
        $amount = (float) ($interval === 'year' ? $plan->price_yearly : $plan->price_monthly);

        if ($plan->isFree() || $amount <= 0 || ! isset(self::INTERVALS[$interval])) {
            throw new RuntimeException('هذه الخطة غير متاحة للاشتراك.');
        }

        $metadata = ['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'interval' => $interval];

        $session = $this->request('post', 'checkout/sessions', [
            'mode' => 'subscription',
            'customer' => $this->customerFor($tenant, $user),
            'client_reference_id' => $tenant->id,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => config('services.stripe.currency'),
                    'unit_amount' => (int) round($amount * 100),
                    'recurring' => ['interval' => $interval],
                    'product' => $this->productFor($plan),
                ],
            ]],
            'metadata' => $metadata,
            'subscription_data' => ['metadata' => $metadata],
            'allow_promotion_codes' => 'true',
            'success_url' => route('tenant.billing.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('tenant.upgrade'),
        ]);

        return $session['url'];
    }

    /**
     * Customer Portal link: update the card, download invoices, cancel.
     */
    public function portal(Tenant $tenant): string
    {
        if (! $tenant->stripe_customer_id) {
            throw new RuntimeException('لا يوجد اشتراك مدفوع لهذا المتجر بعد.');
        }

        return $this->request('post', 'billing_portal/sessions', [
            'customer' => $tenant->stripe_customer_id,
            'return_url' => route('tenant.upgrade'),
        ])['url'];
    }

    /**
     * The Checkout success redirect: activate right away instead of waiting for the webhook.
     * Returns false when the session is not (yet) paid or belongs to another store.
     */
    public function completeCheckout(Tenant $tenant, string $sessionId): bool
    {
        $session = $this->request('get', 'checkout/sessions/'.$sessionId, ['expand' => ['subscription']]);

        if ((string) ($session['client_reference_id'] ?? '') !== (string) $tenant->id
            || ($session['status'] ?? null) !== 'complete'
            || ! is_array($session['subscription'] ?? null)) {
            return false;
        }

        $this->applySubscription($session['subscription']);

        return $tenant->refresh()->isPro();
    }

    /**
     * Re-read the subscription from Stripe (daily safety net for missed webhooks).
     */
    public function refresh(Tenant $tenant): void
    {
        if ($tenant->stripe_subscription_id) {
            $this->applySubscription($this->request('get', 'subscriptions/'.$tenant->stripe_subscription_id));
        }
    }

    /**
     * Verify the `Stripe-Signature` header and return the decoded event.
     */
    public function verifyWebhook(string $payload, ?string $header, int $tolerance = 300): array
    {
        $secret = (string) config('services.stripe.webhook_secret');
        $parts = collect(explode(',', (string) $header))->map(fn ($p) => explode('=', trim($p), 2))->filter(fn ($p) => count($p) === 2);
        $timestamp = (int) ($parts->first(fn ($p) => $p[0] === 't')[1] ?? 0);
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $valid = $parts->contains(fn ($p) => $p[0] === 'v1' && hash_equals($expected, $p[1]));

        if ($secret === '' || ! $valid || abs(time() - $timestamp) > $tolerance) {
            throw new RuntimeException('Invalid Stripe signature.');
        }

        return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Apply a verified webhook event once (Stripe may deliver the same event more than once).
     */
    public function handle(array $event): void
    {
        $fresh = DB::table('stripe_events')->insertOrIgnore([
            'id' => $event['id'], 'type' => $event['type'], 'created_at' => now(),
        ]);

        if (! $fresh) {
            return;
        }

        $object = $event['data']['object'];

        match ($event['type']) {
            'checkout.session.completed' => ($object['mode'] ?? null) === 'subscription' && is_string($object['subscription'] ?? null)
                ? $this->applySubscription($this->request('get', 'subscriptions/'.$object['subscription']))
                : null,
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->applySubscription($object),
            'invoice.paid' => $this->recordInvoice($object, SubscriptionPayment::PAID),
            'invoice.payment_failed' => $this->recordInvoice($object, SubscriptionPayment::FAILED),
            default => null,
        };
    }

    /**
     * Mirror a Stripe subscription onto its tenant.
     */
    public function applySubscription(array $subscription): ?Tenant
    {
        $tenant = $this->tenantFor($subscription['metadata']['tenant_id'] ?? null, $subscription['customer'] ?? null);

        if (! $tenant) {
            return null;
        }

        $status = $subscription['status'];
        $isCurrent = $tenant->stripe_subscription_id === null || $tenant->stripe_subscription_id === $subscription['id'];

        if (in_array($status, self::LIVE, true)) {
            $periodEnd = $subscription['current_period_end'] ?? data_get($subscription, 'items.data.0.current_period_end');
            $plan = Plan::find(data_get($subscription, 'metadata.plan_id')) ?? $tenant->plan;

            $tenant->fill([
                'plan_id' => $plan?->id ?? $tenant->plan_id,
                'stripe_customer_id' => $subscription['customer'] ?? $tenant->stripe_customer_id,
                'stripe_subscription_id' => $subscription['id'],
                'subscription_status' => $status,
                'subscription_interval' => data_get($subscription, 'items.data.0.price.recurring.interval', data_get($subscription, 'metadata.interval')),
                'subscription_cancels' => (bool) ($subscription['cancel_at_period_end'] ?? false),
            ]);

            // A failed renewal still rolls the period forward: only paid periods extend access.
            if ($status !== 'past_due' && $periodEnd) {
                $tenant->subscription_ends_at = CarbonImmutable::createFromTimestamp($periodEnd)->addDays(self::GRACE_DAYS);
            }
        } elseif (in_array($status, self::ENDED, true) && $isCurrent) {
            $tenant->fill([
                'stripe_subscription_id' => null,
                'subscription_status' => $status,
                'subscription_cancels' => false,
                'subscription_ends_at' => now(),
            ]);
        } elseif ($isCurrent) {
            $tenant->subscription_status = $status; // incomplete: waiting on the first payment
        }

        $tenant->save();

        return $tenant;
    }

    private function recordInvoice(array $invoice, string $status): void
    {
        $tenant = $this->tenantFor(null, $invoice['customer'] ?? null);

        if (! $tenant || ! ($invoice['subscription'] ?? data_get($invoice, 'parent.subscription_details.subscription'))) {
            return;
        }

        $end = data_get($invoice, 'lines.data.0.period.end');

        SubscriptionPayment::updateOrCreate(['stripe_invoice_id' => $invoice['id']], [
            'tenant_id' => $tenant->id,
            'amount' => (int) ($status === SubscriptionPayment::PAID ? ($invoice['amount_paid'] ?? 0) : ($invoice['amount_due'] ?? 0)),
            'currency' => $invoice['currency'] ?? config('services.stripe.currency'),
            'status' => $status,
            'hosted_invoice_url' => $invoice['hosted_invoice_url'] ?? null,
            'period_end' => $end ? CarbonImmutable::createFromTimestamp($end) : null,
        ]);
    }

    private function tenantFor(mixed $tenantId, ?string $customerId): ?Tenant
    {
        return ($tenantId ? Tenant::find($tenantId) : null)
            ?? ($customerId ? Tenant::where('stripe_customer_id', $customerId)->first() : null);
    }

    private function customerFor(Tenant $tenant, User $user): string
    {
        if ($tenant->stripe_customer_id) {
            return $tenant->stripe_customer_id;
        }

        $customer = $this->request('post', 'customers', [
            'name' => $tenant->name,
            'email' => $user->email,
            'metadata' => ['tenant_id' => $tenant->id],
        ]);

        $tenant->update(['stripe_customer_id' => $customer['id']]);

        return $customer['id'];
    }

    private function productFor(Plan $plan): string
    {
        if ($plan->stripe_product_id) {
            return $plan->stripe_product_id;
        }

        $product = $this->request('post', 'products', [
            'name' => config('app.name').' — '.$plan->name,
            'metadata' => ['plan_id' => $plan->id],
        ]);

        $plan->update(['stripe_product_id' => $product['id']]);

        return $product['id'];
    }

    private function request(string $method, string $path, array $data = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('الدفع الإلكتروني غير مفعّل حالياً.');
        }

        /** @var Response $response */
        $response = Http::withToken(config('services.stripe.secret'))
            ->asForm()->acceptJson()->timeout(30)
            ->{$method}(self::API.$path, $data);

        if ($response->failed()) {
            report(new RuntimeException('Stripe '.$path.': '.$response->json('error.message', $response->status())));

            throw new RuntimeException('تعذّر الاتصال بخدمة الدفع، حاول مرة أخرى بعد قليل.');
        }

        return $response->json();
    }
}
