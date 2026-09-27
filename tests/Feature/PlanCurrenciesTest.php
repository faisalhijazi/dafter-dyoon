<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Admin;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CurrencyConverter;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Free plan: shekel + dollar are free; every other currency needs the paid plan.
 */
class PlanCurrenciesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $tenant = app(TenantProvisioner::class)->create('بقالة مجانية', Plan::where('slug', 'free')->first());
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
        $this->account = Account::create(['name' => 'رامي', 'notes' => 'test']);
    }

    private function currency(string $iso): Currency
    {
        return Currency::where('iso_code', $iso)->firstOrFail();
    }

    public function test_free_plan_can_use_shekel_and_dollar_only(): void
    {
        $usable = app(CurrencyConverter::class)->usable()->pluck('iso_code')->all();

        $this->assertSame(['ILS', 'USD'], $usable);
        $this->assertFalse($this->user->tenant->canUseCurrency($this->currency('JOD')));
    }

    public function test_recording_in_dollar_works_but_dinar_is_rejected(): void
    {
        Livewire::test('pages::tenant.account-ledger', ['account' => $this->account])
            ->set('amount', '100')->set('currency_id', $this->currency('USD')->id)->set('date', now()->format('Y-m-d'))
            ->call('save', Transaction::DEBIT)
            ->assertHasNoErrors();

        Livewire::test('pages::tenant.account-ledger', ['account' => $this->account])
            ->set('amount', '100')->set('currency_id', $this->currency('JOD')->id)->set('date', now()->format('Y-m-d'))
            ->call('save', Transaction::DEBIT)
            ->assertHasErrors('currency_id');

        $this->assertSame(['USD'], $this->account->transactions()->with('currency')->get()->pluck('currency.iso_code')->all());
    }

    public function test_free_plan_can_exchange_dollars(): void
    {
        Livewire::test('pages::tenant.account-ledger', ['account' => $this->account])
            ->call('openExchange')
            ->assertSet('showExchange', true)
            ->assertSet('exCurrencyId', $this->currency('USD')->id);
    }

    public function test_a_locked_currency_cannot_be_unlocked_by_making_it_the_base(): void
    {
        Livewire::test('pages::tenant.currencies')
            ->call('makeBase', $this->currency('JOD')->id)
            ->assertRedirect(route('tenant.upgrade'));

        $this->assertTrue($this->currency('ILS')->fresh()->is_base);
    }

    public function test_currencies_page_marks_paid_currencies(): void
    {
        $this->get(route('tenant.currencies'))->assertOk()->assertSee('الاحترافية')->assertSee('مجاناً');
    }

    public function test_paid_plan_uses_every_currency(): void
    {
        $this->user->tenant->update(['plan_id' => Plan::where('slug', 'pro')->value('id'), 'subscription_ends_at' => now()->addMonth()]);

        $this->assertCount(6, app(CurrencyConverter::class)->usable());
    }

    public function test_assistant_explains_the_free_currencies(): void
    {
        Livewire::test('pages::tenant.assistant')->set('text', 'سجل 50 دينار على رامي')->call('send')
            ->assertSee('خطتك الحالية تشمل')
            ->assertSee('دولار أمريكي');

        $this->assertSame(0, $this->account->transactions()->count());
    }

    public function test_admin_can_change_the_free_currencies(): void
    {
        $free = Plan::where('slug', 'free')->firstOrFail();

        $this->actingAs(Admin::firstOrFail(), 'admin')->put(route('admin.plans.update', $free), [
            'name' => $free->name, 'price_monthly' => 0, 'price_yearly' => 0,
            'max_accounts' => 30, 'max_transactions' => 500, 'is_active' => 1,
            'free_currencies_text' => 'ils, usd, jod',
        ])->assertRedirect();

        $this->assertSame(['ILS', 'USD', 'JOD'], $free->fresh()->free_currencies);
    }
}
