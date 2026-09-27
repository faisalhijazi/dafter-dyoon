<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountAlias;
use App\Models\Admin;
use App\Models\AssistantMessage;
use App\Models\AssistantModelVersion;
use App\Models\AssistantPersonalPhrase;
use App\Models\AssistantPhraseReview;
use App\Models\AssistantSample;
use App\Models\User;
use App\Services\Assistant\ModelTrainer;
use App\Services\Assistant\PythonNlu;
use App\Services\TenantProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «مساعد AI» self-learning: aliases, samples, «ماذا قصدت؟», personal phrases, nightly retraining.
 */
class AssistantLearningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private string $modelDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->owner = User::where('email', 'demo@daftar.test')->firstOrFail();
        $this->modelDir = storage_path('framework/testing/assistant-'.uniqid());
        config(['assistant.learning.path' => $this->modelDir]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->modelDir);

        parent::tearDown();
    }

    private function chat(?User $user = null)
    {
        $this->actingAs($user ?? $this->owner);

        return Livewire::test('pages::tenant.assistant');
    }

    private function lastReply(?User $user = null): AssistantMessage
    {
        return AssistantMessage::withoutGlobalScope('tenant')->where('user_id', ($user ?? $this->owner)->id)
            ->where('role', 'assistant')->latest('id')->firstOrFail();
    }

    private function account(string $name): Account
    {
        return Account::withoutGlobalScope('tenant')->where('tenant_id', $this->owner->tenant_id)->where('name', $name)->firstOrFail();
    }

    private function otherStoreUser(string $name = 'متجر آخر'): User
    {
        return User::factory()->create(['tenant_id' => app(TenantProvisioner::class)->create($name)->id]);
    }

    public function test_choosing_an_account_teaches_a_nickname_used_next_time(): void
    {
        $othman = $this->account('عثمان ظهير');

        $chat = $this->chat()->set('text', 'كم باقي على عثمان؟')->call('send')->assertSee('هل تقصد');
        $chat->call('choose', $this->lastReply()->id, (string) $othman->id, 'عثمان ظهير');

        $this->assertDatabaseHas('account_aliases', ['account_id' => $othman->id, 'alias' => 'عثمان']);

        // Next time: straight to the answer, no question.
        $this->chat()->set('text', 'كم باقي على عثمان؟')->call('send');
        $reply = $this->lastReply();
        $this->assertStringContainsString('عثمان ظهير', $reply->content);
        $this->assertSame($othman->id, $reply->payload['blocks'][0]['account_id']);
        $this->assertNull($reply->pending(), 'answered directly, no "هل تقصد" question');
        $this->assertSame(1, AccountAlias::withoutGlobalScope('tenant')->where('alias', 'عثمان')->value('hits'));
    }

    public function test_samples_are_masked_and_follow_confirm_and_cancel(): void
    {
        $chat = $this->chat()->set('text', 'سجل 150 شيكل على حمدي الجنجي بضاعة')->call('send');

        $sample = AssistantSample::withoutGlobalScope('tenant')->latest('id')->firstOrFail();
        $this->assertSame('add_debit', $sample->intent);
        $this->assertStringNotContainsString('حمدي', $sample->masked_text);
        $this->assertStringNotContainsString('150', $sample->masked_text);

        $chat->call('confirm', $this->lastReply()->id);
        $this->assertSame('confirmed', $sample->fresh()->source);

        $chat->set('text', 'قبضت 400 شيكل من نانا دفعة')->call('send');
        $cancelled = AssistantSample::withoutGlobalScope('tenant')->latest('id')->firstOrFail();
        $chat->call('cancel', $this->lastReply()->id);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    public function test_what_did_you_mean_teaches_a_personal_phrase_for_this_store_only(): void
    {
        $chat = $this->chat()->set('text', 'نزّل على نانا 40')->call('send');
        $reply = $this->lastReply();

        if (empty($reply->payload['clarify'])) {
            $chat->call('dislike', $reply->id)->assertSee('ماذا قصدت؟');
        }

        $chat->call('clarify', $reply->id, 'add_debit')->assertSee('سأتذكر')->assertSee('تأكيد');

        $this->assertSame('disputed', AssistantSample::withoutGlobalScope('tenant')->where('assistant_message_id', $reply->id)->value('status') ?? 'disputed');
        $phrase = AssistantPersonalPhrase::withoutGlobalScope('tenant')->where('tenant_id', $this->owner->tenant_id)->firstOrFail();
        $this->assertSame('add_debit', $phrase->intent);

        // The same wording (another amount) is now understood directly in this store…
        $this->chat()->set('text', 'نزّل على نانا 70')->call('send');
        $this->assertSame('record', $this->lastReply()->payload['pending']['action'] ?? null);

        // …but not in another store.
        $stranger = $this->otherStoreUser();
        $this->actingAs($stranger);
        $this->assertNotSame('personal', app(PythonNlu::class)->parse('نزّل على نانا 70')['source']);
    }

    public function test_shared_model_learns_phrases_used_by_three_stores_or_approved_by_admin(): void
    {
        $trainer = app(ModelTrainer::class);
        $phrase = 'كتبت ع xname xnum';
        $stores = collect([$this->owner->tenant_id, ...collect(range(1, 2))->map(fn ($i) => $this->otherStoreUser("متجر {$i}")->tenant_id)]);

        foreach ($stores->take(2) as $tenantId) {
            AssistantSample::withoutGlobalScope('tenant')->create(['tenant_id' => $tenantId, 'masked_text' => $phrase, 'intent' => 'add_debit', 'source' => 'confirmed']);
        }
        $this->assertFalse($trainer->learnedPhrases()->has($phrase), 'two stores are not enough');

        AssistantSample::withoutGlobalScope('tenant')->create(['tenant_id' => $stores->last(), 'masked_text' => $phrase, 'intent' => 'add_debit', 'source' => 'answered']);
        $this->assertSame('add_debit', $trainer->learnedPhrases()->get($phrase));

        AssistantPhraseReview::create(['masked_text' => $phrase, 'intent' => 'add_debit', 'decision' => 'rejected']);
        $this->assertFalse($trainer->learnedPhrases()->has($phrase), 'admin rejection wins');

        AssistantPhraseReview::create(['masked_text' => 'رصد xname', 'intent' => 'query_balance', 'decision' => 'approved']);
        $this->assertSame('query_balance', $trainer->learnedPhrases()->get('رصد xname'), 'approval needs no stores');
    }

    public function test_retraining_activates_a_model_that_passes_the_gate_and_the_assistant_uses_it(): void
    {
        AssistantPhraseReview::create(['masked_text' => 'كتبت ع xname xnum', 'intent' => 'add_debit', 'decision' => 'approved']);

        $this->artisan('assistant:learn')->assertSuccessful();

        $version = AssistantModelVersion::latest('id')->firstOrFail();
        $this->assertSame(AssistantModelVersion::ACTIVE, $version->status);
        $this->assertTrue($version->passedGate());
        $this->assertSame(1, $version->learned_samples);
        $this->assertSame($version->path, app(ModelTrainer::class)->activeModelPath());

        $this->actingAs($this->owner);
        $this->assertSame('add_debit', app(PythonNlu::class)->parse('كتبت ع نانا 20')['intent']);
    }

    public function test_admin_can_review_phrases_and_retrain(): void
    {
        AssistantSample::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->owner->tenant_id, 'masked_text' => 'شو وضع xname', 'intent' => null, 'source' => 'unknown',
        ]);
        $admin = Admin::firstOrFail();

        $this->actingAs($admin, 'admin')->get(route('admin.assistant.index'))
            ->assertOk()->assertSee('جمل لم يفهمها المساعد')->assertSee('شو وضع «اسم»');

        $this->actingAs($admin, 'admin')->post(route('admin.assistant.review'), [
            'masked_text' => 'شو وضع xname', 'intent' => 'query_balance', 'decision' => 'approved',
        ])->assertRedirect();
        $this->assertDatabaseHas('assistant_phrase_reviews', ['masked_text' => 'شو وضع xname', 'decision' => 'approved']);

        $this->actingAs($admin, 'admin')->post(route('admin.assistant.train'))->assertRedirect()->assertSessionHas('success');
        $this->assertSame(1, AssistantModelVersion::where('status', 'active')->count());

        $this->actingAs($admin, 'admin')->post(route('admin.assistant.reset'))->assertRedirect();
        $this->assertNull(app(ModelTrainer::class)->activeModelPath());
    }
}
