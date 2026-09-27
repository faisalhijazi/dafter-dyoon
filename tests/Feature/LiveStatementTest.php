<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Plan;
use App\Models\StatementDispute;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StatementMessage;
use App\Services\TenantProvisioner;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LiveStatementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->owner = User::where('email', 'demo@daftar.test')->firstOrFail();
        // Seeded demo account that owes the shop 1,801 ILS.
        $this->account = Account::withoutGlobalScope('tenant')->where('tenant_id', $this->owner->tenant_id)->where('name', 'نانا')->firstOrFail();
    }

    public function test_owner_can_enable_the_link_and_share_it_as_text(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::tenant.account-ledger', ['account' => $this->account])
            ->call('enableStatementLink')
            ->set('showShare', true)
            ->assertSee('/s/'.$this->account->fresh()->statement_token);

        $this->assertNotNull($this->account->fresh()->statement_token);
    }

    public function test_customer_sees_live_balance_and_movements(): void
    {
        $this->account->regenerateStatementToken();

        $this->get($this->account->statementUrl())
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('نانا')
            ->assertSee('1,801.00')
            ->assertSee('عليكم')
            ->assertSee('بضاعة');

        $this->assertNotNull($this->account->fresh()->statement_viewed_at);
    }

    public function test_owner_preview_is_not_counted_as_a_customer_visit(): void
    {
        $this->account->regenerateStatementToken();

        $this->actingAs($this->owner)->get($this->account->statementUrl())->assertOk();

        $this->assertNull($this->account->fresh()->statement_viewed_at);
    }

    public function test_another_shops_user_sees_the_right_statement(): void
    {
        $this->account->regenerateStatementToken();
        $other = app(TenantProvisioner::class)->create('متجر آخر');
        $stranger = User::factory()->create(['tenant_id' => $other->id]);

        $this->actingAs($stranger)->get($this->account->statementUrl())->assertOk()->assertSee('1,801.00');
    }

    public function test_invalid_disabled_and_replaced_links_are_unavailable(): void
    {
        $this->get('/s/'.str_repeat('x', 48))->assertNotFound()->assertSee('غير متاح');

        $this->account->regenerateStatementToken();
        $oldUrl = $this->account->statementUrl();
        $this->account->regenerateStatementToken();
        $this->get($oldUrl)->assertNotFound();
        $this->get($this->account->statementUrl())->assertOk();

        $url = $this->account->statementUrl();
        $this->account->disableStatementLink();
        $this->get($url)->assertNotFound();
    }

    public function test_link_is_unavailable_when_plan_excludes_it_or_tenant_is_suspended(): void
    {
        $this->account->regenerateStatementToken();
        $url = $this->account->statementUrl();
        $tenant = $this->owner->tenant;

        $tenant->plan->update(['live_statement' => false]);
        $this->get($url)->assertNotFound();

        $tenant->plan->update(['live_statement' => true]);
        $tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
        $this->get($url)->assertNotFound();
    }

    public function test_customer_can_dispute_a_movement(): void
    {
        $this->account->regenerateStatementToken();
        $tx = Transaction::withoutGlobalScope('tenant')->where('account_id', $this->account->id)->firstOrFail();

        $this->post(route('statement.dispute', $this->account->statement_token), [
            'transaction_id' => $tx->id,
            'name' => 'نانا',
            'message' => 'المبلغ الصحيح 1500',
        ])->assertRedirect($this->account->statementUrl())->assertSessionHas('success');

        $dispute = StatementDispute::withoutGlobalScope('tenant')->sole();
        $this->assertSame($this->owner->tenant_id, $dispute->tenant_id);
        $this->assertSame($tx->id, $dispute->transaction_id);
        $this->assertTrue($dispute->isOpen());
    }

    public function test_dispute_cannot_reference_another_accounts_movement(): void
    {
        $this->account->regenerateStatementToken();
        $foreign = Transaction::withoutGlobalScope('tenant')->where('account_id', '!=', $this->account->id)->firstOrFail();

        $this->post(route('statement.dispute', $this->account->statement_token), [
            'transaction_id' => $foreign->id,
            'message' => 'اعتراض',
        ])->assertSessionHasErrors('transaction_id');

        $this->assertSame(0, StatementDispute::withoutGlobalScope('tenant')->count());
    }

    public function test_owner_sees_and_resolves_disputes(): void
    {
        $this->account->regenerateStatementToken();
        $this->post(route('statement.dispute', $this->account->statement_token), ['message' => 'الرصيد غير صحيح']);
        $this->actingAs($this->owner);

        Livewire::test('pages::tenant.account-ledger', ['account' => $this->account])
            ->assertSee('اعتراض مفتوح')
            ->set('showDisputes', true)
            ->assertSee('الرصيد غير صحيح')
            ->call('resolveDispute', StatementDispute::sole()->id)
            ->assertDontSee('اعتراض مفتوح');

        $this->assertFalse(StatementDispute::sole()->isOpen());
    }

    public function test_text_summary_includes_balance_and_optional_link(): void
    {
        $this->account->regenerateStatementToken();

        $text = app(TenantContext::class)->runAs($this->owner->tenant, fn () => app(StatementMessage::class)->text($this->account));
        $plain = app(TenantContext::class)->runAs($this->owner->tenant, fn () => app(StatementMessage::class)->text($this->account, withLink: false));

        $this->assertStringContainsString('1,801.00', $text);
        $this->assertStringContainsString('عليكم', $text);
        $this->assertStringContainsString($this->account->statementUrl(), $text);
        $this->assertStringNotContainsString($this->account->statementUrl(), $plain);
    }

    public function test_admin_can_toggle_the_feature_per_plan(): void
    {
        $plan = Plan::where('slug', 'free')->firstOrFail();
        $this->actingAs(\App\Models\Admin::firstOrFail(), 'admin');

        $this->put(route('admin.plans.update', $plan), [
            'name' => $plan->name, 'price_monthly' => 0, 'price_yearly' => 0, 'is_active' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertFalse($plan->fresh()->live_statement);
    }
}
