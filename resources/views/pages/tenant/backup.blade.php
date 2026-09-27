<?php

use App\Models\BackupLog;
use App\Services\BackupService;
use App\Services\GoogleDriveService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts::tenant')] #[Title('النسخ الاحتياطي والاستعادة')] class extends Component {
    use WithFileUploads;

    public $file = null;
    public bool $showRestore = false;
    public bool $showCloudRestore = false;
    public array $cloudFiles = [];

    #[Computed]
    public function logs()
    {
        return BackupLog::latest()->take(8)->get();
    }

    public function download(BackupService $backups)
    {
        $tenant = auth()->user()->tenant;
        $contents = $backups->export($tenant);
        $name = $backups->fileName($tenant);
        $backups->log('local', 'backup', $name, strlen($contents));

        return response()->streamDownload(fn () => print($contents), $name, ['Content-Type' => 'application/octet-stream']);
    }

    public function restore(BackupService $backups): void
    {
        $this->validate(['file' => ['required', 'file', 'max:51200']], attributes: ['file' => 'ملف النسخة']);

        try {
            $result = $backups->restore(auth()->user()->tenant, file_get_contents($this->file->getRealPath()));
            $backups->log('local', 'restore', $this->file->getClientOriginalName(), $this->file->getSize());
            $this->dispatch('toast', message: "تمت الاستعادة: {$result['accounts']} حساب و {$result['transactions']} معاملة.");
        } catch (\RuntimeException $e) {
            $backups->log('local', 'restore', $this->file->getClientOriginalName(), $this->file->getSize(), 'failed', $e->getMessage());
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->reset('file', 'showRestore');
        unset($this->logs);
    }

    public function cloudBackup(BackupService $backups, GoogleDriveService $drive): void
    {
        $tenant = auth()->user()->tenant;

        try {
            $contents = $backups->export($tenant);
            $name = $backups->fileName($tenant);
            $id = $drive->upload($tenant, $name, $contents);
            $backups->log('google', 'backup', $name, strlen($contents), remoteId: $id);
            $this->dispatch('toast', message: 'تم رفع النسخة إلى Google Drive.');
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: $e instanceof \RuntimeException ? $e->getMessage() : 'تعذر الرفع إلى Google Drive.', type: 'error');
        }

        unset($this->logs);
    }

    public function openCloudRestore(GoogleDriveService $drive): void
    {
        try {
            $this->cloudFiles = $drive->list(auth()->user()->tenant);
            $this->showCloudRestore = true;
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: 'تعذر جلب النسخ من Google Drive.', type: 'error');
        }
    }

    public function cloudRestore(string $fileId, BackupService $backups, GoogleDriveService $drive): void
    {
        $tenant = auth()->user()->tenant;
        $file = collect($this->cloudFiles)->firstWhere('id', $fileId);
        abort_unless($file, 404);

        try {
            $result = $backups->restore($tenant, $drive->download($tenant, $fileId));
            $backups->log('google', 'restore', $file['name'], (int) ($file['size'] ?? 0), remoteId: $fileId);
            $this->dispatch('toast', message: "تمت الاستعادة: {$result['accounts']} حساب و {$result['transactions']} معاملة.");
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: $e instanceof \RuntimeException ? $e->getMessage() : 'فشلت الاستعادة.', type: 'error');
        }

        $this->showCloudRestore = false;
        unset($this->logs);
    }

    public function disconnect(GoogleDriveService $drive): void
    {
        $drive->disconnect(auth()->user()->tenant);
        $this->dispatch('toast', message: 'تم فصل حساب Google.');
    }

    public function with(): array
    {
        $tenant = auth()->user()->tenant;

        return [
            'tenant' => $tenant,
            'cloudAllowed' => $tenant->canUse('cloud_backup'),
            'connected' => filled($tenant->google_token),
        ];
    }
}; ?>

<div class="pb-16">
    <x-dd.hero title="النسخ الاحتياطي والاستعادة" :back="route('tenant.settings')" icon="circle-stack" />

    <div class="space-y-8 px-5 pt-8">
        <section>
            <h2 class="mb-4 flex items-center gap-3 text-lg font-bold">
                <span class="flex size-12 items-center justify-center rounded-xl {{ \App\Support\Palette::tile('blue') }}"><flux:icon.device-phone-mobile variant="solid" class="size-6" /></span>
                النسخة الاحتياطية المحلية
                <span class="h-px flex-1 bg-linear-to-l from-slate-200 to-transparent dark:from-slate-700"></span>
            </h2>
            <div class="dd-card divide-y divide-slate-100 overflow-hidden dark:divide-slate-800">
                <x-dd.menu-row wire:click="download" icon="arrow-down-tray" color="blue" title="إنشاء نسخة احتياطية" subtitle="تنزيل ملف مشفّر (.ddbak) يحتوي كل بياناتك." class="px-5" />
                <x-dd.menu-row wire:click="$set('showRestore', true)" icon="arrow-up-tray" color="red" title="استعادة نسخة احتياطية" subtitle="استبدال بياناتك ببيانات من ملف نسخة احتياطية." class="px-5" />
            </div>
        </section>

        <section>
            <h2 class="mb-4 flex items-center gap-3 text-lg font-bold">
                <span class="flex size-12 items-center justify-center rounded-xl {{ \App\Support\Palette::tile('emerald') }}"><flux:icon.cloud-arrow-up variant="solid" class="size-6" /></span>
                النسخ الاحتياطي السحابي
                <span class="h-px flex-1 bg-linear-to-l from-slate-200 to-transparent dark:from-slate-700"></span>
            </h2>
            <div class="dd-card divide-y divide-slate-100 px-5 dark:divide-slate-800">
                <div class="flex items-center gap-4 py-5">
                    <span class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-linear-to-br from-blue-500 to-emerald-500 text-3xl font-extrabold text-white shadow-lg">G</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-lg font-bold">{{ $connected ? 'متصل بحساب Google' : 'تسجيل الدخول' }}</p>
                        <p class="truncate text-sm text-slate-500">{{ $connected ? $tenant->google_email : 'سجل بحساب جوجل لتفعيل المزامنة.' }}</p>
                    </div>
                    @if (! $cloudAllowed)
                        <a href="{{ route('tenant.upgrade') }}" wire:navigate class="dd-btn bg-linear-to-l from-amber-500 to-orange-500 px-4 py-2.5 text-white"><flux:icon.lock-closed variant="micro" /> Pro</a>
                    @elseif ($connected)
                        <button type="button" wire:click="disconnect" wire:confirm="فصل حساب Google؟" class="dd-btn border border-slate-200 px-4 py-2.5 text-slate-600 dark:border-slate-700">فصل</button>
                    @else
                        <a href="{{ route('tenant.google.connect') }}" class="dd-btn bg-linear-to-l from-emerald-500 to-blue-500 px-6 py-3 text-white shadow-lg">دخول</a>
                    @endif
                </div>
                @if ($connected)
                    <x-dd.menu-row wire:click="cloudBackup" wire:loading.class="animate-pulse" wire:target="cloudBackup" icon="cloud-arrow-up" color="emerald" title="إنشاء نسخة احتياطية سحابية" subtitle="حفظ نسخة على حسابك في Google Drive." />
                    <x-dd.menu-row wire:click="openCloudRestore" icon="cloud-arrow-down" color="sky" title="استعادة من نسخة سحابية" subtitle="استعادة بياناتك من نسخة على Google Drive." />
                @else
                    <x-dd.menu-row icon="cloud-arrow-up" color="slate" title="إنشاء نسخة احتياطية سحابية" subtitle="حفظ نسخة على حسابك في Google Drive." class="opacity-50" />
                    <x-dd.menu-row icon="cloud-arrow-down" color="slate" title="استعادة من نسخة سحابية" subtitle="استعادة بياناتك من نسخة على Google Drive." class="opacity-50" />
                @endif
            </div>
        </section>

        @if ($this->logs->isNotEmpty())
            <section>
                <h2 class="dd-section-title mb-3">آخر العمليات</h2>
                <div class="dd-card divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($this->logs as $log)
                        <div class="flex items-center gap-3 px-5 py-3 text-sm">
                            <flux:icon :name="$log->provider === 'google' ? 'cloud' : 'device-phone-mobile'" variant="mini" class="text-slate-400" />
                            <span class="flex-1">
                                <span class="font-bold">{{ $log->action === 'backup' ? 'نسخ' : 'استعادة' }}</span>
                                <span class="block truncate text-xs text-slate-400" dir="ltr">{{ $log->file_name }}</span>
                            </span>
                            <span @class(['dd-badge text-xs', 'bg-emerald-50 text-emerald-600' => $log->status === 'completed', 'bg-rose-50 text-rose-600' => $log->status !== 'completed'])>{{ $log->status === 'completed' ? 'ناجح' : 'فشل' }}</span>
                            <span class="text-xs text-slate-400">{{ $log->created_at->diffForHumans() }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>

    <x-dd.sheet model="showRestore" title="استعادة نسخة احتياطية">
        <form wire:submit="restore" class="space-y-4">
            <p class="flex gap-3 rounded-2xl bg-rose-50 p-4 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                <flux:icon.exclamation-triangle variant="solid" class="size-6 shrink-0" />
                سيتم حذف جميع الحسابات والمعاملات والعملات والأقسام الحالية واستبدالها ببيانات الملف. يُنصح بإنشاء نسخة احتياطية أولاً.
            </p>
            <label class="flex cursor-pointer flex-col items-center gap-2 rounded-3xl border-2 border-dashed border-slate-300 p-8 text-center dark:border-slate-700">
                <flux:icon.arrow-up-tray class="size-10 text-slate-400" />
                <span class="font-bold">{{ $file ? $file->getClientOriginalName() : 'اختر ملف النسخة (.ddbak)' }}</span>
                <input type="file" wire:model="file" class="sr-only">
            </label>
            <div wire:loading wire:target="file" class="text-center text-sm text-slate-500">جاري رفع الملف...</div>
            @error('file') <p class="dd-error">{{ $message }}</p> @enderror
            <button type="submit" wire:confirm="تأكيد استبدال جميع البيانات الحالية؟" class="dd-btn w-full bg-rose-600 text-white" wire:loading.attr="disabled">استعادة الآن</button>
        </form>
    </x-dd.sheet>

    <x-dd.sheet model="showCloudRestore" title="النسخ على Google Drive">
        <div class="space-y-2">
            @forelse ($cloudFiles as $f)
                <button type="button" wire:click="cloudRestore('{{ $f['id'] }}')" wire:confirm="استعادة هذه النسخة؟ سيتم استبدال جميع البيانات الحالية." class="flex w-full items-center gap-3 rounded-2xl border border-slate-100 p-4 text-start dark:border-slate-800">
                    <flux:icon.cloud-arrow-down class="text-sky-500" />
                    <span class="flex-1"><span class="block text-sm font-bold" dir="ltr">{{ $f['name'] }}</span><span class="text-xs text-slate-400">{{ \Carbon\CarbonImmutable::parse($f['createdTime'])->diffForHumans() }} — {{ number_format(($f['size'] ?? 0) / 1024, 1) }} KB</span></span>
                </button>
            @empty
                <p class="py-8 text-center text-slate-500">لا توجد نسخ سحابية بعد.</p>
            @endforelse
        </div>
    </x-dd.sheet>
</div>
