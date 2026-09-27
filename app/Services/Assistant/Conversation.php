<?php

namespace App\Services\Assistant;

use App\Models\Account;
use App\Models\AssistantMessage;
use Illuminate\Support\Facades\Auth;

/**
 * One merchant's chat with «مساعد AI»: stores the messages and turns every interaction into a
 * learning signal (phase 1). Only the newest assistant message can still be acted upon.
 */
class Conversation
{
    public function __construct(private Assistant $assistant, private LearningService $learning) {}

    public function send(string $text): void
    {
        $awaiting = $this->latest();
        $this->store('user', mb_substr($text, 0, 500));
        $this->respond($this->assistant->handle($text, $awaiting?->pending() ? $awaiting : null));
    }

    public function confirm(int $messageId): bool
    {
        if (! $pending = $this->take($messageId, 'confirmed')) {
            return false;
        }

        $this->learning->confirmed($pending['sample_id'] ?? null);
        $this->store('user', '✔ تأكيد');
        $this->respond($this->assistant->confirm($pending), learn: false);

        return true;
    }

    public function cancel(int $messageId): bool
    {
        if (! $pending = $this->take($messageId, 'cancelled')) {
            return false;
        }

        $this->learning->cancelled($pending['sample_id'] ?? null);
        $this->store('user', '✖ إلغاء');
        $this->respond(['text' => 'تم الإلغاء، لم يُسجَّل شيء.', 'intent' => 'cancel', 'blocks' => [], 'pending' => null, 'suggestions' => []], learn: false);

        return true;
    }

    public function choose(int $messageId, string $value, string $label): bool
    {
        if (! $pending = $this->take($messageId, 'chosen')) {
            return false;
        }

        // Picking an existing account for a name we didn't know teaches a nickname.
        if ($value !== 'new' && ($account = Account::find((int) $value)) && ! empty($pending['name_text'])) {
            $this->learning->rememberAlias($account, $pending['name_text']);
        }

        $this->store('user', $label);
        $reply = $this->assistant->choose($pending, $value);

        if ($reply['pending'] !== null) {
            $reply['pending']['sample_id'] = $pending['sample_id'] ?? null;
        }

        $this->respond($reply, learn: false);

        return true;
    }

    /** 👎 on the newest reply: dispute what was learnt from it and ask «ماذا قصدت؟». */
    public function dislike(int $messageId): bool
    {
        $message = $this->latest();

        if (! $message || $message->id !== $messageId || empty($message->payload['nlu'])) {
            return false;
        }

        $this->learning->disputed($message->payload['sample_id'] ?? null);
        $message->update(['payload' => [...$message->payload, 'pending' => null, 'clarify' => true, 'disliked' => true]]);

        return true;
    }

    /** «ماذا قصدت؟» answered: learn the phrase for this store and redo the request with that intent. */
    public function clarify(int $messageId, string $intent): bool
    {
        $message = $this->latest();

        if (! $message || $message->id !== $messageId || empty($message->payload['clarify']) || ! isset(Assistant::INTENT_LABELS[$intent])) {
            return false;
        }

        $original = AssistantMessage::where('user_id', Auth::id())->where('role', 'user')
            ->where('id', '<', $message->id)->latest('id')->first();

        if (! $original) {
            return false;
        }

        $this->learning->corrected($message->payload['sample_id'] ?? null, $message->payload['nlu']['masked_text'] ?? null, $intent, $message->id);
        $message->update(['payload' => [...$message->payload, 'clarify' => false, 'resolved' => 'clarified']]);

        $this->store('user', 'قصدت: '.Assistant::INTENT_LABELS[$intent]);
        $reply = $this->assistant->handle($original->content, null, $intent);
        $reply['text'] = '👍 فهمت، سأتذكر هذه الصيغة. '.$reply['text'];
        $this->respond($reply, learn: false);

        return true;
    }

    public function clear(): void
    {
        AssistantMessage::where('user_id', Auth::id())->delete();
    }

    public function latest(): ?AssistantMessage
    {
        return AssistantMessage::where('user_id', Auth::id())->where('role', 'assistant')->latest('id')->first();
    }

    /**
     * Pops the pending action of message $id if it is still the live one.
     *
     * @return array<string, mixed>|null
     */
    private function take(int $messageId, string $resolution): ?array
    {
        $message = $this->latest();

        if (! $message || $message->id !== $messageId || ! $message->pending()) {
            return null;
        }

        $pending = $message->pending();
        $message->update(['payload' => [...$message->payload, 'pending' => null, 'resolved' => $resolution]]);

        return $pending;
    }

    /**
     * @param  array<string, mixed>  $reply
     */
    private function respond(array $reply, bool $learn = true): AssistantMessage
    {
        $nlu = $reply['nlu'] ?? null;

        $message = $this->store('assistant', $reply['text'], $reply['intent'] ?? null, [
            'blocks' => $reply['blocks'] ?? [],
            'pending' => $reply['pending'] ?? null,
            'suggestions' => $reply['suggestions'] ?? [],
            'nlu' => $nlu,
            // Not understood: offer «ماذا قصدت؟» straight away.
            'clarify' => ($nlu['intent'] ?? null) === 'unknown',
        ]);

        if ($learn && $nlu && ($sample = $this->learning->recordSample($nlu['masked_text'], $nlu['intent'], 'answered', $message->id))) {
            $payload = [...$message->payload, 'sample_id' => $sample->id];

            if ($payload['pending'] !== null) {
                $payload['pending']['sample_id'] = $sample->id;
            }

            $message->update(['payload' => $payload]);
        }

        return $message;
    }

    /** @param  array<string, mixed>|null  $payload */
    private function store(string $role, string $content, ?string $intent = null, ?array $payload = null): AssistantMessage
    {
        return AssistantMessage::create([
            'user_id' => Auth::id(),
            'role' => $role,
            'content' => $content,
            'intent' => $intent,
            'payload' => $payload,
        ]);
    }
}
