<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssistantMessage;
use App\Models\AssistantModelVersion;
use App\Models\AssistantPhraseReview;
use App\Models\AssistantSample;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\ModelTrainer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Phase 3 — «تعلّم المساعد»: label what the assistant did not understand, approve / reject
 * learnt phrases, retrain on demand and roll back model versions.
 */
class AssistantLearningController extends Controller
{
    public function __construct(private ModelTrainer $trainer) {}

    public function index(): View
    {
        $samples = AssistantSample::withoutGlobalScope('tenant');
        $since = now()->subDays(30);
        $recent = (clone $samples)->where('created_at', '>=', $since);
        $total = (clone $recent)->count();

        $reviewed = AssistantPhraseReview::pluck('masked_text')->flip();
        $minStores = (int) config('assistant.learning.min_tenants');

        $group = fn ($query) => $query
            ->groupBy('masked_text', 'intent')
            ->select('masked_text', 'intent', DB::raw('COUNT(*) as uses'), DB::raw('COUNT(DISTINCT tenant_id) as stores'), DB::raw('MAX(created_at) as last_used'))
            ->orderByDesc('uses')
            ->limit(200)
            ->get()
            ->reject(fn ($row) => $reviewed->has($row->masked_text))
            ->take(40)
            ->values();

        return view('admin.assistant.index', [
            'stats' => [
                'questions' => AssistantMessage::withoutGlobalScope('tenant')->where('role', 'user')->where('created_at', '>=', $since)->count(),
                'understood' => $total ? 1 - (clone $recent)->where('source', 'unknown')->count() / $total : null,
                'confirmed' => (clone $recent)->where('source', 'confirmed')->count(),
                'corrected' => (clone $recent)->where('source', 'corrected')->count(),
                'disputed' => (clone $recent)->where('status', 'disputed')->count(),
                'cancelled' => (clone $recent)->where('status', 'cancelled')->count(),
                'learned' => $this->trainer->learnedPhrases()->count(),
            ],
            'active' => $this->trainer->active(),
            'shipped' => $this->shippedMeta(),
            'unknown' => $group((clone $samples)->where('source', 'unknown')),
            'disputed' => $group((clone $samples)->where('status', 'disputed')->whereNotNull('intent')),
            'candidates' => $group((clone $samples)->where('status', 'new')->whereNotNull('intent')->whereIn('source', ['confirmed', 'corrected', 'answered'])),
            'reviews' => AssistantPhraseReview::latest()->take(30)->get(),
            'versions' => AssistantModelVersion::latest('id')->take(10)->get(),
            'intentLabels' => Assistant::INTENT_LABELS,
            'minStores' => $minStores,
        ]);
    }

    public function review(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'masked_text' => ['required', 'string', 'max:300'],
            'intent' => ['nullable', Rule::in(array_keys(Assistant::INTENT_LABELS))],
            'decision' => ['required', Rule::in([AssistantPhraseReview::APPROVED, AssistantPhraseReview::REJECTED])],
        ]);

        if ($data['decision'] === AssistantPhraseReview::APPROVED && empty($data['intent'])) {
            return back()->withErrors(['intent' => 'اختر النية الصحيحة قبل الاعتماد.']);
        }

        AssistantPhraseReview::updateOrCreate(
            ['masked_text' => $data['masked_text'], 'intent' => $data['intent'] ?? null],
            ['decision' => $data['decision'], 'admin_id' => auth('admin')->id()],
        );

        return back()->with('success', $data['decision'] === AssistantPhraseReview::APPROVED
            ? 'اعتُمدت العبارة، وستدخل النموذج في التدريب القادم.'
            : 'رُفضت العبارة ولن يتعلّمها النموذج.');
    }

    public function destroyReview(AssistantPhraseReview $review): RedirectResponse
    {
        $review->delete();

        return back()->with('success', 'أُلغي القرار، وعادت العبارة إلى قائمة المراجعة.');
    }

    public function train(): RedirectResponse
    {
        try {
            $version = $this->trainer->train('admin');
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['train' => 'فشل التدريب: '.$e->getMessage()]);
        }

        return back()->with('success', $version->status === AssistantModelVersion::ACTIVE
            ? sprintf('تم تفعيل النسخة #%d (دقة %.1f%%، %d عبارة متعلَّمة).', $version->id, (float) $version->holdout_accuracy * 100, $version->learned_samples)
            : 'لم تُفعَّل النسخة الجديدة: '.$version->notes);
    }

    public function activate(AssistantModelVersion $version): RedirectResponse
    {
        try {
            $this->trainer->activate($version);
        } catch (Throwable $e) {
            return back()->withErrors(['version' => $e->getMessage()]);
        }

        return back()->with('success', "تم تفعيل النسخة #{$version->id}.");
    }

    public function reset(): RedirectResponse
    {
        $this->trainer->deactivateAll();

        return back()->with('success', 'عاد المساعد إلى النموذج الأساسي المرفق مع الكود.');
    }

    /** @return array<string, mixed> */
    private function shippedMeta(): array
    {
        $path = base_path('ai/model.json');

        if (! is_file($path)) {
            return [];
        }

        // Only the small "meta" header is needed, not the weights.
        $head = (string) file_get_contents($path, length: 400);

        return preg_match('/"meta":(\{[^}]*\})/', $head, $m) ? (json_decode($m[1], true) ?: []) : [];
    }
}
