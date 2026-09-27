<?php

use App\Models\ActivityLog;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::tenant')] #[Title('سجل النشاط')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $user = '';
    #[Url(except: '')]
    public string $type = '';
    #[Url(except: '')]
    public string $date = '';

    public const TYPES = [
        'transaction' => 'المعاملات',
        'account' => 'الحسابات',
        'currency' => 'العملات',
        'team' => 'فريق العمل',
        'payment_report' => 'دفعات الزبائن',
        'customer' => 'تأكيدات الزبائن',
    ];

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function members()
    {
        return User::where('tenant_id', auth()->user()->tenant_id)->orderBy('name')->get(['id', 'name']);
    }

    public function with(): array
    {
        $logs = ActivityLog::with('user')
            ->when($this->user === 'customer', fn ($q) => $q->whereNull('user_id'))
            ->when(ctype_digit($this->user), fn ($q) => $q->where('user_id', (int) $this->user))
            ->when($this->type !== '', fn ($q) => $q->where('event', 'like', $this->type.'.%'))
            ->when($this->date !== '', fn ($q) => $q->whereDate('created_at', $this->date))
            ->latest('id')
            ->paginate(40);

        return [
            'types' => self::TYPES,
            'logs' => $logs,
            'days' => $logs->getCollection()->groupBy(fn (ActivityLog $l) => $l->created_at->format('Y-m-d')),
        ];
    }
}; ?>

<div class="pb-16">
    <x-dd.hero title="سجل النشاط" subtitle="من سجّل أو عدّل أو حذف، ومتى" :back="route('tenant.settings')" icon="clipboard-document-list" />

    <div class="relative -mt-4 space-y-4 px-5">
        <section class="dd-card grid grid-cols-3 gap-2 p-3">
            <select wire:model.live="user" class="dd-input py-2 text-sm">
                <option value="">كل الأشخاص</option>
                @foreach ($this->members as $member)
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endforeach
                <option value="customer">الزبائن (من رابط الكشف)</option>
            </select>
            <select wire:model.live="type" class="dd-input py-2 text-sm">
                <option value="">كل الأنواع</option>
                @foreach ($types as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <input type="date" wire:model.live="date" class="dd-input py-2 text-sm" dir="ltr">
        </section>

        @forelse ($days as $day => $entries)
            <section>
                <h2 class="mb-2 text-sm font-bold text-slate-500">{{ \Carbon\CarbonImmutable::parse($day)->translatedFormat('l j F Y') }}</h2>
                <div class="dd-card divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($entries as $log)
                        <div class="flex items-start gap-3 p-4">
                            <span @class(['mt-1 size-2.5 shrink-0 rounded-full', 'bg-rose-500' => $log->tone() === 'rose', 'bg-emerald-500' => $log->tone() === 'emerald', 'bg-amber-500' => $log->tone() === 'amber', 'bg-slate-400' => $log->tone() === 'slate'])></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm leading-relaxed"><b>{{ $log->user?->name ?? 'الزبون' }}</b> {{ $log->description }}</p>
                                @if (! empty($log->properties['before']))
                                    <p class="mt-1 text-xs text-slate-400">
                                        @foreach ($log->properties['after'] ?? [] as $field => $value)
                                            {{ $field }}: <span class="line-through">{{ is_scalar($log->properties['before'][$field] ?? null) ? $log->properties['before'][$field] : '—' }}</span> ← {{ is_scalar($value) ? $value : '—' }}@if (! $loop->last) · @endif
                                        @endforeach
                                    </p>
                                @endif
                            </div>
                            <span class="shrink-0 text-xs text-slate-400" dir="ltr">{{ $log->created_at->format('h:i A') }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <p class="py-16 text-center text-slate-500">لا يوجد نشاط مطابق.</p>
        @endforelse

        <div>{{ $logs->links() }}</div>
    </div>
</div>
