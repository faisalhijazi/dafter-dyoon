<?php

namespace App\Services\Assistant;

use App\Models\AssistantModelVersion;
use App\Models\AssistantPhraseReview;
use App\Models\AssistantSample;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Phase 2 — nightly self-learning.
 *
 * 1. Collect learned phrases: masked sentences used at ≥ `min_tenants` stores and never
 *    disputed/cancelled, plus everything the admin approved, minus what the admin rejected.
 * 2. Retrain a candidate model through Python (template corpus + learned phrases).
 * 3. Gate: every acceptance phrase must pass and hold-out accuracy may not drop more than
 *    `accuracy_tolerance` versus the active model; otherwise the candidate is rejected.
 * 4. Keep the last `keep_versions` model files so any version can be re-activated.
 *
 * Samples live in tenant tables, so these queries deliberately bypass the tenant scope.
 */
class ModelTrainer
{
    private function dir(): string
    {
        return config('assistant.learning.path');
    }

    public function active(): ?AssistantModelVersion
    {
        return AssistantModelVersion::where('status', AssistantModelVersion::ACTIVE)->latest('activated_at')->first();
    }

    /** Path of the active learnt model, or null to use the shipped ai/model.json. */
    public function activeModelPath(): ?string
    {
        $active = $this->active();

        return $active?->fileExists() ? $active->path : null;
    }

    /**
     * Phrases the shared model should learn: [masked_text => intent].
     *
     * @return Collection<string, string>
     */
    public function learnedPhrases(): Collection
    {
        $minTenants = max(1, (int) config('assistant.learning.min_tenants'));

        $reviews = AssistantPhraseReview::all();
        $rejected = $reviews->where('decision', AssistantPhraseReview::REJECTED)
            ->map(fn ($r) => $r->masked_text.'|'.$r->intent)->flip();

        // Community phrases: distinct stores per (phrase, intent), ignoring disputed / cancelled uses.
        $community = AssistantSample::withoutGlobalScope('tenant')
            ->whereNotNull('intent')
            ->where('status', 'new')
            ->groupBy('masked_text', 'intent')
            ->select('masked_text', 'intent', DB::raw('COUNT(DISTINCT tenant_id) as stores'))
            ->havingRaw('COUNT(DISTINCT tenant_id) >= ?', [$minTenants])
            ->toBase()
            ->get()
            ->map(fn ($r) => ['text' => $r->masked_text, 'intent' => $r->intent, 'weight' => (int) $r->stores]);

        $approved = $reviews->where('decision', AssistantPhraseReview::APPROVED)->whereNotNull('intent')
            ->map(fn ($r) => ['text' => $r->masked_text, 'intent' => $r->intent, 'weight' => PHP_INT_MAX]);

        // One intent per phrase: admin approval wins, otherwise the intent used by the most stores (ties dropped).
        return $community->concat($approved)
            ->reject(fn ($p) => $rejected->has($p['text'].'|'.$p['intent']))
            ->groupBy('text')
            ->map(function (Collection $options) {
                $sorted = $options->sortByDesc('weight')->values();
                $top = $sorted->first();
                $second = $sorted->get(1);

                return $second && $second['weight'] === $top['weight'] && $second['intent'] !== $top['intent'] ? null : $top['intent'];
            })
            ->filter();
    }

    public function train(string $trigger = 'schedule'): AssistantModelVersion
    {
        File::ensureDirectoryExists($this->dir().'/models');

        $phrases = $this->learnedPhrases();
        $stamp = now()->format('Ymd-His');
        $extra = $this->dir()."/learned-{$stamp}.json";
        $candidate = $this->dir()."/models/model-{$stamp}.json";
        $report = $this->dir()."/report-{$stamp}.json";

        File::put($extra, json_encode(
            $phrases->map(fn ($intent, $text) => ['text' => $text, 'intent' => $intent])->values()->all(),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        try {
            $result = Process::timeout(300)
                ->env(PythonNlu::environment())
                ->run([config('assistant.python'), config('assistant.script'), 'train', '--extra', $extra, '--out', $candidate, '--report', $report]);

            if (! $result->successful() || ! is_file($report)) {
                throw new RuntimeException('Training failed: '.trim($result->errorOutput() ?: $result->output()));
            }

            $data = json_decode(File::get($report), true);
        } finally {
            File::delete([$extra, $report]);
        }

        $version = AssistantModelVersion::create([
            'path' => $candidate,
            'status' => AssistantModelVersion::REJECTED,
            'template_samples' => $data['samples'],
            'learned_samples' => $data['learned'],
            'holdout_accuracy' => $data['holdout_accuracy'],
            'acceptance_passed' => $data['acceptance_passed'],
            'acceptance_total' => $data['acceptance_total'],
            'trigger' => $trigger,
        ]);

        if ($reason = $this->gateFailure($version, $data['failures'] ?? [])) {
            $version->update(['notes' => $reason]);
            File::delete($candidate);
        } else {
            $this->activate($version);
        }

        $this->prune();

        return $version->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $failures
     */
    private function gateFailure(AssistantModelVersion $candidate, array $failures): ?string
    {
        if (! $candidate->passedGate()) {
            $texts = collect($failures)->pluck('text')->take(5)->implode('، ');

            return 'فشل في '.count($failures)." من جمل القبول: {$texts}";
        }

        $active = $this->active();
        $tolerance = (float) config('assistant.learning.accuracy_tolerance');

        if ($active && (float) $candidate->holdout_accuracy < (float) $active->holdout_accuracy - $tolerance) {
            return sprintf('انخفضت الدقة من %.1f%% إلى %.1f%%', (float) $active->holdout_accuracy * 100, (float) $candidate->holdout_accuracy * 100);
        }

        return null;
    }

    public function activate(AssistantModelVersion $version): void
    {
        if (! $version->fileExists()) {
            throw new RuntimeException('ملف هذه النسخة لم يعد موجوداً.');
        }

        DB::transaction(function () use ($version) {
            AssistantModelVersion::where('status', AssistantModelVersion::ACTIVE)->update(['status' => AssistantModelVersion::ARCHIVED]);
            $version->update(['status' => AssistantModelVersion::ACTIVE, 'activated_at' => now()]);
        });
    }

    /** Back to the model shipped with the code (ai/model.json). */
    public function deactivateAll(): void
    {
        AssistantModelVersion::where('status', AssistantModelVersion::ACTIVE)->update(['status' => AssistantModelVersion::ARCHIVED]);
    }

    /** Keep the newest archived files; the active one is never deleted. */
    private function prune(): void
    {
        AssistantModelVersion::where('status', AssistantModelVersion::ARCHIVED)
            ->latest('id')
            ->skip((int) config('assistant.learning.keep_versions'))
            ->take(PHP_INT_MAX)
            ->get()
            ->each(function (AssistantModelVersion $v) {
                File::delete($v->path);
                $v->update(['notes' => trim(($v->notes ?? '').' (حُذف الملف)')]);
            });
    }
}
