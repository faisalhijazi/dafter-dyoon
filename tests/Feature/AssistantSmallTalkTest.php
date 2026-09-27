<?php

namespace Tests\Feature;

use App\Models\AssistantMessage;
use App\Models\AssistantSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «مساعد AI» answers greetings, courtesies and general questions (config/assistant_smalltalk.php).
 */
class AssistantSmallTalkTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->owner = User::where('email', 'demo@daftar.test')->firstOrFail(); // "صاحب المتجر"

        // One reply per entry so assertions are deterministic.
        config([
            'assistant_smalltalk.morning.replies' => ['صباح النور يا {name} في {store}'],
            'assistant_smalltalk.salam.replies' => ['وعليكم السلام ورحمة الله'],
            'assistant_smalltalk.how_are_you.replies' => ['الحمد لله بخير'],
            'assistant_smalltalk.thanks.replies' => ['العفو يا {name}'],
        ]);
    }

    private function say(string $text): AssistantMessage
    {
        $this->actingAs($this->owner);
        Livewire::test('pages::tenant.assistant')->set('text', $text)->call('send');

        return AssistantMessage::withoutGlobalScope('tenant')->where('user_id', $this->owner->id)
            ->where('role', 'assistant')->latest('id')->firstOrFail();
    }

    public function test_it_answers_greetings_with_the_merchants_name_and_store(): void
    {
        $reply = $this->say('صباح الخير');

        $this->assertSame('smalltalk', $reply->intent);
        $this->assertSame('صباح النور يا صاحب في متجر النور', $reply->content);
        $this->assertSame('العفو يا صاحب', $this->say('شكراً كتير')->content);
    }

    public function test_several_greetings_in_one_message_are_all_answered(): void
    {
        $this->assertSame('وعليكم السلام 🌷 الحمد لله بخير', $this->say('السلام عليكم، كيف حالك؟')->content);
    }

    public function test_a_greeting_before_a_command_is_returned_then_the_command_is_answered(): void
    {
        $reply = $this->say('السلام عليكم كم باقي على نانا؟');

        $this->assertSame('query_balance', $reply->intent);
        $this->assertStringStartsWith('وعليكم السلام 🌷', $reply->content);
        $this->assertStringContainsString('1,801.00', $reply->content);
    }

    public function test_general_questions_get_an_answer_with_a_link(): void
    {
        $reply = $this->say('كيف اضيف زبون؟');

        $this->assertSame('smalltalk', $reply->intent);
        $this->assertSame(route('tenant.accounts.create'), $reply->payload['blocks'][0]['url']);
    }

    public function test_small_talk_is_not_used_as_learning_data(): void
    {
        $this->say('مرحبا');
        $this->say('مين انت؟');

        $this->assertSame(0, AssistantSample::withoutGlobalScope('tenant')->count());
    }
}
