<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Stripe subscriptions: Checkout, the success redirect, signed webhooks, renewals and cancellation.
 * The Stripe API is always faked; no request leaves the test.
 */
class StripeBillingTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test';

    private Tenant $tenant;

    private User $owner;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.webhook_secret' => self::SECRET,
            'services.stripe.currency' => 'usd',
        ]);
        Http::preventStrayRequests();

        $this->seed();
        $this->pro = Plan::where('slug', 'pro')->firstOrFail();
        $this->tenant = app(TenantProvisioner::class)->create('بقالة الاختبار', Plan::where('slug', 'free')->first());
        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->owner);
    }

    private function subscription(string $status = 'active', array $extra = []): array
    {
        return [
            'id' => 'sub_1', 'object' => 'subscription', 'customer' => 'cus_1', 'status' => $status,
            'cancel_at_period_end' => false,
            'metadata' => ['tenant_id' => (string) $this->tenant->id, 'plan_id' => (string) $this->pro->id, 'interval' => 'month'],
            'items' => ['data' => [['current_period_end' => now()->addMonth()->timestamp, 'price' => ['recurring' => ['interval' => 'month']]]]],
            ...$extra,
        ];
    }

    private function webhook(string $type, array $object, string $id = 'evt_1', ?string $secret = null)
    {
        $payload = json_encode(['id' => $id, 'type' => $type, 'data' => ['object' => $object]]);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$payload, $secret ?? self::SECRET);

        return $this->call('POST', '/stripe/webhook', server: [
            'HTTP_STRIPE_SIGNATURE' => "t={$time},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], content: $payload);
    }

    public function test_upgrade_page_offers_card_checkout(): void
    {
        $this->get(route('tenant.upgrade'))->assertOk()->assertSee('اشترك الآن بالبطاقة')->assertSee(route('tenant.billing.checkout'));
    }

    public function test_checkout_creates_customer_product_and_session(): void
    {
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/products' => Http::response(['id' => 'prod_1']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
        ]);

        $this->post(route('tenant.billing.checkout'), ['plan_id' => $this->pro->id, 'interval' => 'year'])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');

        $this->assertSame('cus_1', $this->tenant->fresh()->stripe_customer_id);
        $this->assertSame('prod_1', $this->pro->fresh()->stripe_product_id);

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), 'checkout/sessions')) {
                return false;
            }

            return $request['mode'] === 'subscription'
                && $request['customer'] === 'cus_1'
                && (int) $request['line_items'][0]['price_data']['unit_amount'] === (int) round($this->pro->price_yearly * 100)
                && $request['line_items'][0]['price_data']['recurring']['interval'] === 'year'
                && (string) $request['subscription_data']['metadata']['tenant_id'] === (string) $this->tenant->id;
        });
    }

    public function test_free_plan_cannot_be_checked_out_and_staff_cannot_pay(): void
    {
        $this->post(route('tenant.billing.checkout'), ['plan_id' => Plan::where('slug', 'free')->value('id'), 'interval' => 'month'])
            ->assertRedirect(route('tenant.upgrade'))->assertSessionHas('error');

        $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => User::ROLE_STAFF]);
        $this->actingAs($staff)->post(route('tenant.billing.checkout'), ['plan_id' => $this->pro->id, 'interval' => 'month'])->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_success_redirect_activates_the_plan(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_1*' => Http::response([
            'id' => 'cs_1', 'status' => 'complete', 'client_reference_id' => (string) $this->tenant->id, 'subscription' => $this->subscription(),
        ])]);

        $this->get(route('tenant.billing.success', ['session_id' => 'cs_1']))->assertRedirect(route('tenant.upgrade'))->assertSessionHas('success');

        $tenant = $this->tenant->fresh();
        $this->assertTrue($tenant->isPro());
        $this->assertSame('sub_1', $tenant->stripe_subscription_id);
        $this->assertTrue($tenant->canUse('multi_currency'));
    }

    public function test_success_redirect_ignores_another_stores_session(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions/*' => Http::response([
            'id' => 'cs_x', 'status' => 'complete', 'client_reference_id' => '999999', 'subscription' => $this->subscription(),
        ])]);

        $this->get(route('tenant.billing.success', ['session_id' => 'cs_x']));

        $this->assertFalse($this->tenant->fresh()->isPro());
    }

    public function test_webhook_rejects_bad_signatures(): void
    {
        $this->webhook('customer.subscription.created', $this->subscription(), secret: 'whsec_wrong')->assertStatus(400);

        $this->assertFalse($this->tenant->fresh()->isPro());
    }

    public function test_webhooks_activate_renew_and_cancel(): void
    {
        $this->webhook('customer.subscription.created', $this->subscription(), 'evt_1')->assertOk();
        $this->assertTrue($this->tenant->fresh()->isPro());

        // Renewal paid: recorded once even if Stripe delivers the event twice.
        $invoice = ['id' => 'in_1', 'customer' => 'cus_1', 'subscription' => 'sub_1', 'amount_paid' => 900, 'currency' => 'usd',
            'hosted_invoice_url' => 'https://invoice.stripe.com/i/1', 'lines' => ['data' => [['period' => ['end' => now()->addMonth()->timestamp]]]]];
        $this->webhook('invoice.paid', $invoice, 'evt_2')->assertOk();
        $this->webhook('invoice.paid', [...$invoice, 'amount_paid' => 1], 'evt_2')->assertOk();
        $this->assertSame(900, SubscriptionPayment::sole()->amount);

        // Card failing: plan kept while Stripe retries, but the paid period is not extended.
        $endsAt = $this->tenant->fresh()->subscription_ends_at;
        $this->webhook('customer.subscription.updated', $this->subscription('past_due', ['items' => ['data' => [['current_period_end' => now()->addMonths(2)->timestamp]]]]), 'evt_3');
        $this->assertTrue($this->tenant->fresh()->isPro());
        $this->assertEquals($endsAt, $this->tenant->fresh()->subscription_ends_at);

        $this->webhook('customer.subscription.deleted', $this->subscription('canceled'), 'evt_4')->assertOk();
        $tenant = $this->tenant->fresh();
        $this->assertFalse($tenant->isPro());
        $this->assertNull($tenant->stripe_subscription_id);
        $this->assertSame('free', $tenant->effectivePlan()->slug);
    }

    public function test_daily_sync_refreshes_subscriptions_close_to_expiry(): void
    {
        $this->tenant->update(['plan_id' => $this->pro->id, 'stripe_customer_id' => 'cus_1', 'stripe_subscription_id' => 'sub_1', 'subscription_ends_at' => now()->addDay()]);
        Http::fake(['api.stripe.com/v1/subscriptions/sub_1' => Http::response($this->subscription())]);

        $this->artisan('billing:sync')->assertSuccessful();

        $this->assertTrue($this->tenant->fresh()->subscription_ends_at->gt(now()->addDays(25)));
    }
}
