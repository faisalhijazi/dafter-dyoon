<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AssistantMessage;
use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «مساعد AI» end to end: Livewire page -> Laravel -> local Python engine -> ledger.
 */
class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->owner = User::where('email', 'demo@daftar.test')->firstOrFail();
    }

    private function chat(User $user)
    {
        $this->actingAs($user);

        return Livewire::test('pages::tenant.assistant');
    }

    private function lastReply(User $user): AssistantMessage
    {
        return AssistantMessage::withoutGlobalScope('tenant')->where('user_id', $user->id)->where('role', 'assistant')->latest('id')->firstOrFail();
    }

    private function account(string $name): Account
    {
        return Account::withoutGlobalScope('tenant')->where('tenant_id', $this->owner->tenant_id)->where('name', $name)->firstOrFail();
    }

    public function test_free_and_paid_plans_can_open_the_assistant(): void
    {
        $this->actingAs($this->owner)->get(route('tenant.assistant'))->assertOk()->assertSee('مساعد AI');

        $free = app(TenantProvisioner::class)->create('متجر مجاني', Plan::where('slug', 'free')->first());
        $user = User::factory()->create(['tenant_id' => $free->id]);

        $this->actingAs($user)->get(route('tenant.assistant'))->assertOk()->assertSee('كيف أقدر أساعدك اليوم؟');
    }

    public function test_it_answers_a_balance_question(): void
    {
        $this->chat($this->owner)
            ->set('text', 'كم باقي على نانا؟')
            ->call('send')
            ->assertSee('1,801.00')
            ->assertSee('عليه');
    }

    public function test_a_debit_is_recorded_only_after_confirmation(): void
    {
        $account = $this->account('حمدي الجنجي');
        $before = $account->transactions()->count();

        $chat = $this->chat($this->owner)->set('text', 'سجل 150 شيكل على حمدي الجنجي بضاعة')->call('send')->assertSee('تأكيد');

        $this->assertSame($before, $account->transactions()->count(), 'nothing is written before confirming');

        $reply = $this->lastReply($this->owner);
        $chat->call('confirm', $reply->id)->assertSee('تم التسجيل');

        $tx = $account->transactions()->latest('id')->first();
        $this->assertSame($before + 1, $account->transactions()->count());
        $this->assertSame(Transaction::DEBIT, $tx->type);
        $this->assertEquals(150, (float) $tx->amount);
        $this->assertSame('بضاعة', $tx->notes);

        // Replaying the same confirmation must not record twice.
        $chat->call('confirm', $reply->id);
        $this->assertSame($before + 1, $account->transactions()->count());
    }

    public function test_cancel_discards_the_pending_action(): void
    {
        $account = $this->account('نانا');
        $before = $account->transactions()->count();

        $chat = $this->chat($this->owner)->set('text', 'قبضت 400 شيكل من نانا دفعة')->call('send');
        $chat->call('cancel', $this->lastReply($this->owner)->id)->assertSee('تم الإلغاء');

        $this->assertSame($before, $account->transactions()->count());
    }

    public function test_partial_name_offers_choices_then_records_in_the_spoken_currency(): void
    {
        $account = $this->account('عثمان ظهير');

        $chat = $this->chat($this->owner)->set('text', 'قبضت 50 دولار من عثمان دفعة')->call('send')->assertSee('عثمان ظهير');
        $chat->call('choose', $this->lastReply($this->owner)->id, (string) $account->id, 'عثمان ظهير')->assertSee('تأكيد');
        $chat->call('confirm', $this->lastReply($this->owner)->id);

        $tx = $account->transactions()->with('currency')->latest('id')->first();
        $this->assertSame(Transaction::CREDIT, $tx->type);
        $this->assertEquals(50, (float) $tx->amount);
        $this->assertSame('دولار', $tx->currency->code);
    }

    public function test_it_cannot_see_another_tenants_accounts(): void
    {
        $other = app(TenantProvisioner::class)->create('متجر آخر');
        $stranger = User::factory()->create(['tenant_id' => $other->id]);

        $this->chat($stranger)
            ->set('text', 'كم باقي على نانا؟')
            ->call('send')
            ->assertDontSee('1,801.00')
            ->assertSee('لا يوجد حساب باسم');
    }

    public function test_reports_run_through_the_engine(): void
    {
        $this->chat($this->owner)
            ->set('text', 'مين أكثر 3 زبائن عليهم ديون؟')
            ->call('send')
            ->assertSee('نانا');

        $this->chat($this->owner)
            ->set('text', 'كم سعر صرف الدولار؟')
            ->call('send')
            ->assertSee('3.7');
    }
}
