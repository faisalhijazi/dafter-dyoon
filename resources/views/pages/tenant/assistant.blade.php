<?php

use App\Models\AccountAlias;
use App\Models\AssistantMessage;
use App\Models\AssistantPersonalPhrase;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\Conversation;
use App\Services\Assistant\LearningService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::tenant')] #[Title('مساعد AI')] class extends Component {
    public string $text = '';

    public bool $showMemory = false;

    #[Computed]
    public function messages()
    {
        return AssistantMessage::where('user_id', auth()->id())
            ->latest('id')->take(config('assistant.history'))->get()->reverse()->values();
    }

    #[Computed]
    public function aliases()
    {
        return AccountAlias::with('account')->latest('hits')->latest('id')->get();
    }

    #[Computed]
    public function phrases()
    {
        return AssistantPersonalPhrase::latest('hits')->latest('id')->get();
    }

    public function send(Conversation $conversation): void
    {
        $text = trim($this->text);
        $this->text = '';

        if ($text === '') {
            return;
        }

        $key = 'assistant:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, config('assistant.rate_limit'))) {
            $this->dispatch('toast', message: 'رسائل كثيرة خلال وقت قصير، انتظر لحظة.', type: 'error');

            return;
        }
        RateLimiter::hit($key, 60);

        $conversation->send($text);
        $this->refreshFeed();
    }

    public function ask(string $text, Conversation $conversation): void
    {
        $this->text = $text;
        $this->send($conversation);
    }

    public function confirm(int $id, Conversation $conversation): void
    {
        $this->after($conversation->confirm($id));
    }

    public function cancel(int $id, Conversation $conversation): void
    {
        $this->after($conversation->cancel($id));
    }

    public function choose(int $id, string $value, string $label, Conversation $conversation): void
    {
        $this->after($conversation->choose($id, $value, $label));
    }

    public function dislike(int $id, Conversation $conversation): void
    {
        $this->after($conversation->dislike($id));
    }

    public function clarify(int $id, string $intent, Conversation $conversation): void
    {
        $this->after($conversation->clarify($id, $intent));
    }

    public function clear(Conversation $conversation): void
    {
        $conversation->clear();
        unset($this->messages);
    }

    public function forgetAlias(int $id): void
    {
        AccountAlias::whereKey($id)->delete();
        unset($this->aliases);
        $this->dispatch('toast', message: 'نسي المساعد هذا الاسم المختصر.');
    }

    public function forgetPhrase(int $id): void
    {
        AssistantPersonalPhrase::whereKey($id)->delete();
        unset($this->phrases);
        $this->dispatch('toast', message: 'نسي المساعد هذه العبارة.');
    }

    private function after(bool $ok): void
    {
        if (! $ok) {
            $this->dispatch('toast', message: 'انتهت صلاحية هذا الإجراء، أعد كتابة الطلب.', type: 'error');
        }

        $this->refreshFeed();
    }

    private function refreshFeed(): void
    {
        unset($this->messages, $this->aliases, $this->phrases);
        $this->dispatch('assistant-replied');
    }

    public function with(): array
    {
        return [
            'liveId' => $this->messages->where('role', 'assistant')->last()?->id,
            'suggestions' => Assistant::SUGGESTIONS,
            'intentLabels' => Assistant::INTENT_LABELS,
        ];
    }
}; ?>

<div class="flex h-dvh flex-col"
     x-data="{
        sending: '',
        scroll() { this.$nextTick(() => this.$refs.feed.scrollTo({ top: this.$refs.feed.scrollHeight, behavior: 'smooth' })) },
     }"
     x-init="scroll()"
     x-on:assistant-replied.window="sending = ''; scroll()">

    {{-- Header --}}
    <header class="flex shrink-0 items-center gap-3 bg-white px-4 py-4 shadow-sm dark:bg-slate-900">
        <a href="{{ route('dashboard') }}" wire:navigate class="dd-icon-btn size-11 bg-slate-100 text-slate-500 dark:bg-slate-800" aria-label="رجوع"><flux:icon.arrow-right class="size-6" /></a>
        <span class="relative flex size-14 items-center justify-center rounded-2xl bg-linear-to-br from-violet-500 via-indigo-500 to-blue-500 text-white shadow-lg shadow-indigo-500/30">
            <flux:icon.sparkles variant="solid" class="size-7" />
            <span class="absolute -bottom-0.5 -left-0.5 size-4 rounded-full border-2 border-white bg-emerald-500 dark:border-slate-900"></span>
        </span>
        <div class="min-w-0 flex-1">
            <h1 class="text-2xl font-bold">مساعد AI</h1>
            <p class="flex items-center gap-1 text-xs text-slate-500"><flux:icon.lock-closed variant="micro" class="size-3.5" /> يعمل على خوادمنا — بياناتك لا تغادر المنصة</p>
        </div>
        <button type="button" wire:click="$set('showMemory', true)" class="dd-icon-btn size-11 bg-indigo-50 text-indigo-600 dark:bg-indigo-500/10" title="ذاكرة المساعد"><flux:icon.light-bulb variant="mini" /></button>
        @if ($this->messages->isNotEmpty())
            <button type="button" wire:click="clear" wire:confirm="مسح سجل المحادثة؟" class="dd-icon-btn size-11 bg-slate-100 text-slate-500 dark:bg-slate-800" title="محادثة جديدة"><flux:icon.trash variant="mini" /></button>
        @endif
    </header>

    {{-- Feed --}}
    <div x-ref="feed" class="flex-1 space-y-4 overflow-y-auto px-4 py-5">
        @if ($this->messages->isEmpty())
            <div class="flex flex-col items-center pt-6 text-center">
                <span class="flex size-24 items-center justify-center rounded-[2rem] bg-linear-to-br from-violet-500 via-indigo-500 to-blue-500 text-white shadow-2xl shadow-indigo-500/40">
                    <flux:icon.sparkles variant="solid" class="size-12" />
                </span>
                <h2 class="mt-6 text-2xl font-extrabold">كيف أقدر أساعدك اليوم؟</h2>
                <p class="mt-2 max-w-sm text-slate-500">اكتب طلبك بكلامك العادي: سجّل ديناً، اسأل عن رصيد، أو اطلب تقريراً عن المتأخرين.</p>
                <div class="mt-6 grid w-full gap-2 sm:grid-cols-2">
                    @foreach ($suggestions as $s)
                        <button type="button" wire:click="ask(@js($s))" x-on:click="sending = @js($s)" class="rounded-2xl border border-slate-200 bg-white p-4 text-start text-sm font-medium transition hover:border-indigo-300 hover:bg-indigo-50 dark:border-slate-800 dark:bg-slate-900 dark:hover:bg-indigo-500/10">
                            {{ $s }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        @foreach ($this->messages as $message)
            @if ($message->isUser())
                <div wire:key="m-{{ $message->id }}" class="flex justify-end">
                    <div class="max-w-[80%] rounded-3xl rounded-bl-md bg-slate-900 px-5 py-3 text-white shadow-sm dark:bg-indigo-600">{{ $message->content }}</div>
                </div>
            @else
                @php($live = $message->id === $liveId)
                <div wire:key="m-{{ $message->id }}" class="flex items-start gap-2">
                    <span class="mt-1 flex size-9 shrink-0 items-center justify-center rounded-xl bg-linear-to-br from-violet-500 to-blue-500 text-white"><flux:icon.sparkles variant="micro" /></span>
                    <div class="min-w-0 max-w-[88%] space-y-3">
                        <div class="rounded-3xl rounded-br-md bg-white px-5 py-3 leading-relaxed shadow-sm ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800">{{ $message->content }}</div>

                        @foreach ($message->payload['blocks'] ?? [] as $block)
                            @include('tenant.assistant.block', ['block' => $block, 'message' => $message, 'live' => $live])
                        @endforeach

                        @if (($message->payload['resolved'] ?? null) === 'cancelled')
                            <p class="text-xs text-slate-400">أُلغيت</p>
                        @endif

                        {{-- «ماذا قصدت؟»: teach the assistant what this sentence meant --}}
                        @if ($live && ! empty($message->payload['clarify']))
                            <div class="rounded-3xl border border-dashed border-indigo-300 bg-indigo-50/60 p-4 dark:border-indigo-500/40 dark:bg-indigo-500/10">
                                <p class="mb-3 flex items-center gap-2 text-sm font-bold text-indigo-700 dark:text-indigo-300"><flux:icon.light-bulb variant="mini" /> ماذا قصدت؟ اختر وسأتعلّم هذه الصيغة</p>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($intentLabels as $intent => $label)
                                        <button type="button" wire:click="clarify({{ $message->id }}, '{{ $intent }}')" class="rounded-full bg-white px-3 py-1.5 text-sm font-medium shadow-sm ring-1 ring-indigo-100 hover:bg-indigo-600 hover:text-white dark:bg-slate-900 dark:ring-indigo-500/30">{{ $label }}</button>
                                    @endforeach
                                </div>
                            </div>
                        @elseif ($live && ! empty($message->payload['nlu']) && ($message->payload['nlu']['intent'] ?? null) !== 'unknown' && empty($message->payload['resolved']))
                            <button type="button" wire:click="dislike({{ $message->id }})" class="flex items-center gap-1 text-xs text-slate-400 hover:text-rose-500" title="الرد غير صحيح">
                                <flux:icon.hand-thumb-down variant="micro" /> لم يفهمني
                            </button>
                        @endif

                        @if ($live && ! empty($message->payload['suggestions']))
                            <div class="flex flex-wrap gap-2">
                                @foreach ($message->payload['suggestions'] as $s)
                                    <button type="button" wire:click="ask(@js($s))" x-on:click="sending = @js($s)" class="rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-sm text-indigo-700 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-300">{{ $s }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        @endforeach

        {{-- Optimistic bubble + typing indicator while the engine works --}}
        <template x-if="sending">
            <div class="space-y-4">
                <div class="flex justify-end"><div class="max-w-[80%] rounded-3xl rounded-bl-md bg-slate-900 px-5 py-3 text-white opacity-70 dark:bg-indigo-600" x-text="sending"></div></div>
            </div>
        </template>
        <div wire:loading.flex wire:target="send,ask,confirm,choose,cancel" class="items-center gap-2">
            <span class="flex size-9 items-center justify-center rounded-xl bg-linear-to-br from-violet-500 to-blue-500 text-white"><flux:icon.sparkles variant="micro" /></span>
            <span class="flex gap-1 rounded-3xl bg-white px-5 py-4 shadow-sm dark:bg-slate-900">
                <span class="size-2 animate-bounce rounded-full bg-indigo-400"></span>
                <span class="size-2 animate-bounce rounded-full bg-indigo-400 [animation-delay:150ms]"></span>
                <span class="size-2 animate-bounce rounded-full bg-indigo-400 [animation-delay:300ms]"></span>
            </span>
        </div>
    </div>

    {{-- What the assistant learnt from this store (phase 3) --}}
    <x-dd.sheet model="showMemory" title="ذاكرة المساعد">
        <p class="mb-5 rounded-2xl bg-slate-50 p-4 text-sm text-slate-500 dark:bg-slate-800/60">
            يتعلّم المساعد من استخدامك: عندما تختار حساباً لاسم مختصر يحفظه، وعندما تصحّح له عبر «ماذا قصدت؟» يحفظ صيغتك. هذه الذاكرة خاصة بمتجرك فقط.
        </p>

        <h3 class="dd-section-title mb-3 text-indigo-500">الأسماء المختصرة ({{ $this->aliases->count() }})</h3>
        <div class="mb-6 space-y-2">
            @forelse ($this->aliases as $alias)
                <div wire:key="al-{{ $alias->id }}" class="flex items-center gap-3 rounded-2xl border border-slate-100 p-3 dark:border-slate-800">
                    <span class="font-bold">«{{ $alias->alias }}»</span>
                    <flux:icon.arrow-left variant="micro" class="text-slate-400" />
                    <span class="flex-1 truncate text-slate-600 dark:text-slate-300">{{ $alias->account?->name }}</span>
                    <span class="text-xs text-slate-400">استُخدم {{ $alias->hits }} مرة</span>
                    <button type="button" wire:click="forgetAlias({{ $alias->id }})" class="dd-icon-btn size-9 bg-rose-50 text-rose-500 dark:bg-rose-500/10" title="انسَ"><flux:icon.x-mark variant="micro" /></button>
                </div>
            @empty
                <p class="text-sm text-slate-400">لا توجد بعد. اكتب اسماً مختصراً لحساب واختر الحساب الصحيح من القائمة.</p>
            @endforelse
        </div>

        <h3 class="dd-section-title mb-3 text-violet-500">صيغ علّمتها للمساعد ({{ $this->phrases->count() }})</h3>
        <div class="space-y-2">
            @forelse ($this->phrases as $phrase)
                <div wire:key="ph-{{ $phrase->id }}" class="flex items-center gap-3 rounded-2xl border border-slate-100 p-3 dark:border-slate-800">
                    <span class="min-w-0 flex-1 text-sm"><span class="block truncate font-bold">{{ \App\Services\Assistant\LearningService::readable($phrase->masked_text) }}</span><span class="text-xs text-indigo-600">{{ $intentLabels[$phrase->intent] ?? $phrase->intent }}</span></span>
                    <button type="button" wire:click="forgetPhrase({{ $phrase->id }})" class="dd-icon-btn size-9 bg-rose-50 text-rose-500 dark:bg-rose-500/10" title="انسَ"><flux:icon.x-mark variant="micro" /></button>
                </div>
            @empty
                <p class="text-sm text-slate-400">لا توجد بعد. عندما لا يفهمك المساعد اضغط «لم يفهمني» واختر ما قصدته.</p>
            @endforelse
        </div>
    </x-dd.sheet>

    {{-- Composer --}}
    <form wire:submit="send" x-on:submit="sending = $refs.input.value; scroll()" class="shrink-0 border-t border-slate-100 bg-white p-3 dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-end gap-2">
            <textarea x-ref="input" wire:model="text" rows="1" maxlength="500" placeholder="اكتب أمرك… مثلاً: سجل 150 شيكل على أبو أحمد"
                      x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $el.form.requestSubmit() }"
                      x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 140) + 'px'"
                      class="dd-input max-h-36 flex-1 resize-none rounded-3xl py-3.5" autofocus></textarea>
            <span x-data="ddVoice()" x-show="supported" x-cloak class="shrink-0">
                <button type="button" x-on:click="toggle($refs.input)" :class="listening ? 'bg-rose-500 text-white animate-pulse' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'"
                        class="dd-icon-btn size-13" :aria-label="listening ? 'إيقاف التسجيل' : 'تحدّث بدلاً من الكتابة'" title="إدخال صوتي (يعالجه المتصفح)">
                    <flux:icon.microphone variant="solid" class="size-6" />
                </button>
            </span>
            <button type="submit" wire:loading.attr="disabled" class="dd-icon-btn size-13 shrink-0 bg-linear-to-br from-violet-500 to-blue-500 text-white shadow-lg shadow-indigo-500/30 disabled:opacity-50" aria-label="إرسال">
                <flux:icon.paper-airplane variant="solid" class="size-6 -scale-x-100" />
            </button>
        </div>
    </form>
</div>
