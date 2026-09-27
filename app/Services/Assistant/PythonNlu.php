<?php

namespace App\Services\Assistant;

use App\Models\Account;
use App\Models\AccountAlias;
use App\Models\AssistantPersonalPhrase;
use App\Models\Category;
use App\Services\CurrencyConverter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Bridge to the local Python NLU engine (ai/assistant.py).
 *
 * Sends the text plus the tenant's own account names (and learnt aliases), currencies,
 * categories and personal phrases so the engine can resolve entities, and returns
 * {intent, confidence, source, masked_text, entities}. Nothing leaves the server.
 */
class PythonNlu
{
    public function __construct(private CurrencyConverter $converter, private ModelTrainer $trainer) {}

    /**
     * @param  string|null  $forceIntent  the merchant's answer to «ماذا قصدت؟»
     * @return array<string, mixed>
     */
    public function parse(string $text, ?string $forceIntent = null): array
    {
        $accounts = Account::query()->get(['id', 'name', 'category_id']);
        $aliases = AccountAlias::query()->whereIn('account_id', $accounts->modelKeys())->get(['account_id', 'alias']);

        $payload = [
            'text' => mb_substr($text, 0, 500),
            // Aliases are sent as extra names pointing at the same account id.
            'accounts' => [
                ...$accounts->map(fn (Account $a) => ['id' => $a->id, 'name' => $a->name])->all(),
                ...$aliases->map(fn (AccountAlias $a) => ['id' => $a->account_id, 'name' => $a->alias])->all(),
            ],
            'currencies' => $this->converter->currencies()->values()
                ->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'is_base' => $c->is_base])->all(),
            'categories' => Category::query()->get(['id', 'name'])->toArray(),
            'phrases' => AssistantPersonalPhrase::query()->get(['masked_text', 'intent'])
                ->map(fn ($p) => ['text' => $p->masked_text, 'intent' => $p->intent])->all(),
            'force_intent' => $forceIntent,
            // Greetings & general questions, editable in config/assistant_smalltalk.php.
            'smalltalk' => collect(config('assistant_smalltalk', []))
                ->map(fn ($entry, $key) => ['key' => $key, 'patterns' => $entry['patterns'] ?? []])->values()->all(),
        ];

        $command = [config('assistant.python'), config('assistant.script'), 'parse'];
        if ($model = $this->trainer->activeModelPath()) {
            array_push($command, '--model', $model);
        }

        $result = Process::timeout(config('assistant.timeout'))
            ->env(['PYTHONIOENCODING' => 'utf-8', 'PYTHONUTF8' => '1'])
            ->input(json_encode($payload, JSON_UNESCAPED_UNICODE))
            ->run($command);

        $data = json_decode(trim($result->output()), true);

        if (! $result->successful() || ! is_array($data) || isset($data['error'])) {
            throw new RuntimeException('NLU engine failed: '.($data['error'] ?? trim($result->errorOutput()) ?: 'no output'));
        }

        return $this->resolveAliases($data, $accounts->pluck('name', 'id')->all(), $aliases);
    }

    /**
     * Alias matches come back under the alias; show the real account name, keep one entry per
     * account (best score) and count how often each alias helped.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $names
     * @param  Collection<int, AccountAlias>  $aliases
     * @return array<string, mixed>
     */
    private function resolveAliases(array $data, array $names, $aliases): array
    {
        $candidates = collect($data['entities']['account_candidates'] ?? [])
            ->sortByDesc('score')
            ->unique('id')
            ->map(fn ($c) => [...$c, 'alias' => $c['name'] !== ($names[$c['id']] ?? $c['name']) ? $c['name'] : null, 'name' => $names[$c['id']] ?? $c['name']])
            ->values();

        $data['entities']['account_candidates'] = $candidates->all();
        $data['entities']['account'] = $candidates->first();

        $best = $candidates->first();
        if ($best && $best['alias'] && $best['match'] === 'exact') {
            AccountAlias::query()->where('account_id', $best['id'])->where('alias', $best['alias'])->increment('hits');
        }

        return $data;
    }
}
