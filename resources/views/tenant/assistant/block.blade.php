{{-- One structured card inside an assistant reply. $block, $message, $live --}}
@php
    $tone = fn ($t) => match ($t) {
        'credit' => 'text-emerald-600',
        'debit' => 'text-rose-600',
        'warning' => 'text-amber-600',
        default => 'text-slate-700 dark:text-slate-200',
    };
@endphp

@switch($block['type'])
    @case('confirm')
        <div @class([
            'overflow-hidden rounded-3xl border-2 bg-white dark:bg-slate-900',
            'border-emerald-200 dark:border-emerald-500/30' => $block['tone'] === 'credit',
            'border-rose-200 dark:border-rose-500/30' => $block['tone'] === 'debit',
            'border-indigo-200 dark:border-indigo-500/30' => $block['tone'] === 'neutral',
        ])>
            <p @class([
                'px-5 py-3 font-bold',
                'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10' => $block['tone'] === 'credit',
                'bg-rose-50 text-rose-700 dark:bg-rose-500/10' => $block['tone'] === 'debit',
                'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10' => $block['tone'] === 'neutral',
            ])>{{ $block['title'] }}</p>
            <dl class="divide-y divide-slate-100 px-5 dark:divide-slate-800">
                @foreach ($block['rows'] as [$label, $value])
                    <div class="flex justify-between gap-4 py-2.5 text-sm"><dt class="text-slate-500">{{ $label }}</dt><dd class="text-left font-bold">{{ $value }}</dd></div>
                @endforeach
            </dl>
            @if (! empty($block['warning']))
                <p class="mx-4 mb-3 rounded-xl bg-amber-50 p-3 text-sm font-medium text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">{{ $block['warning'] }}</p>
            @endif
            @if ($live && $message->pending())
                <div class="grid grid-cols-2 gap-2 p-4 pt-1">
                    <button type="button" wire:click="cancel({{ $message->id }})" class="dd-btn border border-slate-200 py-2.5 text-slate-600 dark:border-slate-700">إلغاء</button>
                    <button type="button" wire:click="confirm({{ $message->id }})" wire:loading.attr="disabled" class="dd-btn-primary py-2.5"><flux:icon.check variant="mini" /> تأكيد</button>
                </div>
            @elseif (($message->payload['resolved'] ?? null) === 'confirmed')
                <p class="flex items-center gap-1 px-5 pb-3 text-xs font-bold text-emerald-600"><flux:icon.check-circle variant="micro" /> تم التأكيد</p>
            @endif
        </div>
        @break

    @case('choices')
        <div class="flex flex-wrap gap-2">
            @foreach ($block['options'] as $option)
                <button type="button" @disabled(! $live || ! $message->pending())
                        wire:click="choose({{ $message->id }}, @js($option['value']), @js($option['label']))"
                        class="rounded-2xl border border-indigo-200 bg-white px-4 py-2.5 text-sm font-bold text-indigo-700 transition hover:bg-indigo-50 disabled:opacity-40 dark:border-indigo-500/30 dark:bg-slate-900 dark:text-indigo-300">
                    {{ $option['label'] }}
                </button>
            @endforeach
        </div>
        @break

    @case('account')
        <a href="{{ $block['url'] }}" wire:navigate class="block rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-100 transition hover:ring-indigo-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="flex items-center gap-3">
                <span class="flex size-11 items-center justify-center rounded-xl text-lg {{ \App\Support\Palette::avatar($block['account_id']) }}">{{ \Illuminate\Support\Str::substr($block['name'], 0, 1) }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate font-bold">{{ $block['name'] }}</p>
                    <p class="text-xs text-slate-400">{{ $block['category'] }}</p>
                </div>
                @if ($block['limit'] === 'exceeded')
                    <span class="dd-badge bg-rose-600 text-xs text-white">تجاوز السقف</span>
                @elseif ($block['limit'] === 'warning')
                    <span class="dd-badge bg-amber-100 text-xs text-amber-700">قريب من السقف</span>
                @endif
                <flux:icon.chevron-left variant="mini" class="text-slate-400" />
            </div>
            <div class="mt-3 space-y-1.5 border-t border-slate-100 pt-3 dark:border-slate-800">
                @forelse ($block['balances'] as $b)
                    <div class="flex items-center justify-between">
                        <span class="font-bold tabular-nums {{ $tone($b['tone']) }}">{{ $b['amount'] }}</span>
                        <span @class(['dd-badge text-xs', 'bg-emerald-50 text-emerald-700' => $b['tone'] === 'credit', 'bg-rose-50 text-rose-600' => $b['tone'] === 'debit'])>{{ $b['status'] }}</span>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">متزن — لا يوجد رصيد</p>
                @endforelse
                @if ($block['total'])
                    <p class="pt-1 text-xs text-slate-500">الإجمالي بالعملة الأساسية: <b>{{ $block['total'] }}</b></p>
                @endif
            </div>
        </a>
        @break

    @case('list')
        <div class="overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800">
            @if (! empty($block['title']))
                <p class="border-b border-slate-100 px-5 py-3 text-sm font-bold text-slate-500 dark:border-slate-800">{{ $block['title'] }}</p>
            @endif
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($block['rows'] as $row)
                    @php($tag = ! empty($row['url']) ? 'a' : 'div')
                    <{{ $tag }} @if ($tag === 'a') href="{{ $row['url'] }}" wire:navigate @endif class="flex items-center gap-3 px-5 py-3 {{ $tag === 'a' ? 'hover:bg-slate-50 dark:hover:bg-slate-800' : '' }}">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-bold">{{ $row['name'] }}</p>
                            @if (! empty($row['meta'])) <p class="truncate text-xs text-slate-400">{{ $row['meta'] }}</p> @endif
                        </div>
                        <span class="shrink-0 font-bold tabular-nums {{ $tone($row['tone'] ?? null) }}">{{ $row['value'] }}</span>
                    </{{ $tag }}>
                @endforeach
            </div>
            @if (! empty($block['link']))
                <a href="{{ $block['link']['url'] }}" wire:navigate class="block border-t border-slate-100 px-5 py-3 text-center text-sm font-bold text-indigo-600 dark:border-slate-800">{{ $block['link']['label'] }}</a>
            @endif
        </div>
        @break

    @case('stats')
        <div class="grid grid-cols-3 gap-2">
            @foreach ($block['items'] as $item)
                <div @class([
                    'rounded-2xl border p-3 text-center',
                    'border-emerald-200 bg-emerald-50 dark:border-emerald-500/20 dark:bg-emerald-500/10' => $item['tone'] === 'credit',
                    'border-rose-200 bg-rose-50 dark:border-rose-500/20 dark:bg-rose-500/10' => $item['tone'] === 'debit',
                ])>
                    <p class="text-xs text-slate-500">{{ $item['label'] }}</p>
                    <p class="mt-1 text-sm font-extrabold tabular-nums {{ $tone($item['tone']) }}">{{ $item['value'] }}</p>
                </div>
            @endforeach
        </div>
        @if (! empty($block['note']))
            <p class="text-xs text-slate-400">{{ $block['note'] }}</p>
        @endif
        @break

    @case('whatsapp')
        <div class="overflow-hidden rounded-3xl bg-white shadow-sm ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800">
            <pre class="max-h-56 overflow-y-auto whitespace-pre-wrap bg-[#e7ffdb] p-4 font-sans text-sm leading-relaxed text-slate-800 dark:bg-emerald-950 dark:text-emerald-100">{{ $block['preview'] }}</pre>
            <div class="grid grid-cols-2 gap-2 p-3">
                <button type="button" x-data x-on:click="ddCopyToast(@js($block['preview']), 'تم نسخ الرسالة')" class="dd-btn border border-slate-200 py-2.5 text-sm text-slate-600 dark:border-slate-700">نسخ النص</button>
                <a href="{{ $block['url'] }}" target="_blank" rel="noopener" class="dd-btn bg-[#25D366] py-2.5 text-sm text-white">فتح واتساب</a>
            </div>
        </div>
        @break

    @case('link')
        <a href="{{ $block['url'] }}" wire:navigate class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-4 py-2 text-sm font-bold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
            {{ $block['label'] }} <flux:icon.chevron-left variant="micro" />
        </a>
        @break

    @case('examples')
        <div class="space-y-3">
            @foreach ($block['groups'] as $group)
                <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-100 dark:bg-slate-900 dark:ring-slate-800">
                    <p class="mb-2 font-bold">{{ $group['title'] }}</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($group['items'] as $item)
                            <button type="button" wire:click="ask(@js($item))" class="rounded-full bg-slate-100 px-3 py-1.5 text-sm hover:bg-indigo-50 hover:text-indigo-700 dark:bg-slate-800">{{ $item }}</button>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
        @break
@endswitch
