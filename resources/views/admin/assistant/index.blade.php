@extends('admin.layout')

@section('title', 'تعلّم المساعد')
@section('heading', 'تعلّم مساعد AI')

@php
    $readable = fn ($t) => \App\Services\Assistant\LearningService::readable($t);
    $select = 'rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm outline-none focus:border-indigo-400';
@endphp

@section('content')
    <div class="space-y-6">
        {{-- Stats --}}
        <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ([
                ['أسئلة (30 يوماً)', number_format($stats['questions']), 'text-slate-900'],
                ['نسبة الفهم', $stats['understood'] === null ? '—' : round($stats['understood'] * 100).'%', 'text-emerald-600'],
                ['عمليات مؤكَّدة', number_format($stats['confirmed']), 'text-emerald-600'],
                ['تصحيحات «ماذا قصدت؟»', number_format($stats['corrected']), 'text-indigo-600'],
                ['ردود «لم يفهمني»', number_format($stats['disputed']), 'text-rose-600'],
                ['عبارات متعلَّمة', number_format($stats['learned']), 'text-violet-600'],
            ] as [$label, $value, $tone])
                <div class="rounded-[28px] bg-white p-5 shadow-sm ring-1 ring-slate-100">
                    <div class="text-xs text-slate-500">{{ $label }}</div>
                    <div class="mt-2 text-2xl font-black {{ $tone }}">{{ $value }}</div>
                </div>
            @endforeach
        </div>

        {{-- Model & training --}}
        <section class="rounded-[32px] bg-white p-6 shadow-sm ring-1 ring-slate-100">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-lg font-black">النموذج الحالي</h2>
                    @if ($active)
                        <p class="mt-1 text-sm text-slate-500">
                            النسخة #{{ $active->id }} — دقة {{ number_format($active->holdout_accuracy * 100, 1) }}% —
                            {{ $active->learned_samples }} عبارة متعلَّمة — فُعّلت {{ $active->activated_at?->diffForHumans() }}
                        </p>
                    @else
                        <p class="mt-1 text-sm text-slate-500">
                            النموذج الأساسي المرفق مع الكود
                            @if ($shipped) — دقة {{ number_format(($shipped['holdout_accuracy'] ?? 0) * 100, 1) }}%، {{ $shipped['acceptance_passed'] ?? 0 }}/{{ $shipped['acceptance_total'] ?? 0 }} جمل قبول @endif
                        </p>
                    @endif
                    <p class="mt-2 text-xs text-slate-400">
                        يُعاد التدريب تلقائياً كل ليلة الساعة {{ config('assistant.learning.schedule') }}. تدخل العبارة النموذج العام عند استخدامها في {{ $minStores }} متاجر مختلفة على الأقل، أو فور اعتمادك لها.
                        لا تُفعَّل أي نسخة جديدة إلا إذا نجحت في كل جمل القبول ولم تنخفض دقتها.
                    </p>
                </div>
                <div class="flex gap-2">
                    @if ($active)
                        <form method="POST" action="{{ route('admin.assistant.reset') }}" onsubmit="return confirm('العودة إلى النموذج الأساسي؟')">
                            @csrf
                            <button class="rounded-full bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-600">العودة للأساسي</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('admin.assistant.train') }}">
                        @csrf
                        <button class="rounded-full bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-indigo-600/20 hover:bg-indigo-700">إعادة التدريب الآن</button>
                    </form>
                </div>
            </div>

            @if ($versions->isNotEmpty())
                <div class="mt-5 overflow-x-auto">
                    <table class="w-full text-right text-sm">
                        <thead class="text-xs text-slate-400">
                            <tr><th class="py-2">النسخة</th><th>الحالة</th><th>الدقة</th><th>جمل القبول</th><th>متعلَّمة</th><th>المصدر</th><th>التاريخ</th><th>ملاحظات</th><th></th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($versions as $v)
                                <tr>
                                    <td class="py-2.5 font-bold">#{{ $v->id }}</td>
                                    <td>
                                        <span @class(['rounded-full px-2.5 py-0.5 text-xs font-bold',
                                            'bg-emerald-50 text-emerald-700' => $v->status === 'active',
                                            'bg-slate-100 text-slate-500' => $v->status === 'archived',
                                            'bg-rose-50 text-rose-600' => $v->status === 'rejected'])>
                                            {{ ['active' => 'مفعّلة', 'archived' => 'مؤرشفة', 'rejected' => 'مرفوضة'][$v->status] ?? $v->status }}
                                        </span>
                                    </td>
                                    <td>{{ number_format($v->holdout_accuracy * 100, 1) }}%</td>
                                    <td>{{ $v->acceptance_passed }}/{{ $v->acceptance_total }}</td>
                                    <td>{{ $v->learned_samples }}</td>
                                    <td>{{ $v->trigger === 'admin' ? 'يدوي' : 'ليلي' }}</td>
                                    <td class="text-slate-500">{{ $v->created_at->format('Y/m/d H:i') }}</td>
                                    <td class="max-w-xs truncate text-xs text-slate-500" title="{{ $v->notes }}">{{ $v->notes }}</td>
                                    <td>
                                        @if ($v->status === 'archived' && $v->fileExists())
                                            <form method="POST" action="{{ route('admin.assistant.versions.activate', $v) }}">
                                                @csrf
                                                <button class="text-xs font-bold text-indigo-600">تفعيل</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        {{-- Queues --}}
        @foreach ([
            ['unknown', 'جمل لم يفهمها المساعد', 'صنّفها لتعليم المساعد مباشرة — هذا أسرع طريق لتحسينه.', $unknown],
            ['disputed', 'ردود قال عنها التاجر «لم يفهمني»', 'النية المعروضة هي ما خمّنه المساعد خطأً. اختر الصحيحة أو ارفض.', $disputed],
            ['candidates', 'عبارات مرشحة للتعلّم', "تُعتمد تلقائياً عند وصولها إلى {$minStores} متاجر؛ يمكنك اعتمادها الآن أو رفضها.", $candidates],
        ] as [$key, $title, $hint, $rows])
            <section class="rounded-[32px] bg-white p-6 shadow-sm ring-1 ring-slate-100">
                <h2 class="text-lg font-black">{{ $title }} <span class="text-sm font-medium text-slate-400">({{ $rows->count() }})</span></h2>
                <p class="mt-1 text-sm text-slate-500">{{ $hint }}</p>

                <div class="mt-4 divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <div class="flex flex-wrap items-center gap-3 py-3">
                            <div class="min-w-0 flex-1">
                                <div class="font-bold">{{ $readable($row->masked_text) }}</div>
                                <div class="text-xs text-slate-400">
                                    {{ $row->uses }} استخدام في {{ $row->stores }} متجر
                                    @if ($row->intent) — {{ $key === 'disputed' ? 'خمّن' : 'النية' }}: {{ $intentLabels[$row->intent] ?? $row->intent }} @endif
                                </div>
                            </div>
                            <form method="POST" action="{{ route('admin.assistant.review') }}" class="flex items-center gap-2">
                                @csrf
                                <input type="hidden" name="masked_text" value="{{ $row->masked_text }}">
                                <input type="hidden" name="decision" value="approved">
                                <select name="intent" class="{{ $select }}" required>
                                    <option value="">— النية الصحيحة —</option>
                                    @foreach ($intentLabels as $intent => $label)
                                        <option value="{{ $intent }}" @selected($key === 'candidates' && $row->intent === $intent)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                <button class="rounded-full bg-emerald-600 px-4 py-2 text-xs font-bold text-white">اعتماد</button>
                            </form>
                            <form method="POST" action="{{ route('admin.assistant.review') }}">
                                @csrf
                                <input type="hidden" name="masked_text" value="{{ $row->masked_text }}">
                                <input type="hidden" name="intent" value="{{ $key === 'unknown' ? '' : $row->intent }}">
                                <input type="hidden" name="decision" value="rejected">
                                <button class="rounded-full bg-rose-50 px-4 py-2 text-xs font-bold text-rose-600">رفض</button>
                            </form>
                        </div>
                    @empty
                        <p class="py-6 text-center text-sm text-slate-400">لا شيء هنا حالياً.</p>
                    @endforelse
                </div>
            </section>
        @endforeach

        {{-- Past decisions --}}
        @if ($reviews->isNotEmpty())
            <section class="rounded-[32px] bg-white p-6 shadow-sm ring-1 ring-slate-100">
                <h2 class="text-lg font-black">قراراتك الأخيرة</h2>
                <div class="mt-4 divide-y divide-slate-100">
                    @foreach ($reviews as $review)
                        <div class="flex items-center gap-3 py-2.5 text-sm">
                            <span @class(['rounded-full px-2.5 py-0.5 text-xs font-bold', 'bg-emerald-50 text-emerald-700' => $review->decision === 'approved', 'bg-rose-50 text-rose-600' => $review->decision === 'rejected'])>
                                {{ $review->decision === 'approved' ? 'معتمدة' : 'مرفوضة' }}
                            </span>
                            <span class="flex-1 font-medium">{{ $readable($review->masked_text) }}</span>
                            <span class="text-slate-500">{{ $review->intent ? ($intentLabels[$review->intent] ?? $review->intent) : '—' }}</span>
                            <form method="POST" action="{{ route('admin.assistant.reviews.destroy', $review) }}">
                                @csrf
                                @method('DELETE')
                                <button class="text-xs text-slate-400 hover:text-rose-600">تراجع</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
@endsection
