<?php

namespace App\Services\Assistant;

use App\Models\Account;
use App\Models\AccountAlias;
use App\Models\AssistantPersonalPhrase;
use App\Models\AssistantSample;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Captures what merchants teach the assistant, implicitly (confirm / cancel / pick an account)
 * and explicitly (👎, «ماذا قصدت؟»). Everything is stored per tenant and privacy-masked.
 */
class LearningService
{
    /** Greetings and help carry no bookkeeping signal worth learning. */
    private const IGNORED_INTENTS = ['greeting', 'help', 'smalltalk'];

    public function recordSample(?string $maskedText, ?string $intent, string $source, ?int $messageId = null): ?AssistantSample
    {
        $maskedText = trim((string) $maskedText);

        if ($maskedText === '' || in_array($intent, self::IGNORED_INTENTS, true)) {
            return null;
        }

        return AssistantSample::create([
            'user_id' => Auth::guard('web')->id(),
            'assistant_message_id' => $messageId,
            'masked_text' => Str::limit($maskedText, 290, ''),
            'intent' => $intent === 'unknown' ? null : $intent,
            'source' => $intent === 'unknown' ? 'unknown' : $source,
        ]);
    }

    public function confirmed(?int $sampleId): void
    {
        AssistantSample::whereKey($sampleId)->update(['source' => 'confirmed', 'status' => 'new']);
    }

    public function cancelled(?int $sampleId): void
    {
        AssistantSample::whereKey($sampleId)->where('source', '!=', 'confirmed')->update(['status' => 'cancelled']);
    }

    public function disputed(?int $sampleId): void
    {
        AssistantSample::whereKey($sampleId)->update(['status' => 'disputed']);
    }

    /**
     * «ماذا قصدت؟»: the merchant says what a sentence meant. The wrong guess is disputed, the
     * right intent is recorded, and the phrase is remembered for this store right away.
     */
    public function corrected(?int $wrongSampleId, ?string $maskedText, string $intent, ?int $messageId = null): void
    {
        $this->disputed($wrongSampleId);
        $this->recordSample($maskedText, $intent, 'corrected', $messageId);
        $this->teachPhrase($maskedText, $intent);
    }

    public function teachPhrase(?string $maskedText, string $intent): void
    {
        $maskedText = trim((string) $maskedText);

        if ($maskedText === '' || in_array($intent, [...self::IGNORED_INTENTS, 'unknown'], true)) {
            return;
        }

        AssistantPersonalPhrase::updateOrCreate(['masked_text' => Str::limit($maskedText, 290, '')], ['intent' => $intent])->increment('hits');
    }

    /** The merchant picked `$account` after typing `$typed`: remember it as a nickname. */
    public function rememberAlias(Account $account, ?string $typed): ?AccountAlias
    {
        $alias = trim(preg_replace('/\s+/u', ' ', (string) $typed));

        if (mb_strlen($alias) < 2 || mb_strlen($alias) > 120 || $alias === $account->name) {
            return null;
        }

        return AccountAlias::updateOrCreate(['alias' => $alias], ['account_id' => $account->id]);
    }

    /** Human-readable form of a masked phrase for the UI. */
    public static function readable(string $masked): string
    {
        return strtr($masked, ['xname' => '«اسم»', 'xnum' => '«مبلغ»', 'xphone' => '«جوال»']);
    }
}
