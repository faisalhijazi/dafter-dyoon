<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\TenantProvisioner;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        Admin::updateOrCreate(['email' => 'admin@daftar.test'], [
            'name' => 'مدير المنصة',
            'password' => 'password',
            'is_active' => true,
        ]);

        $this->seedDemoTenant();
    }

    private function seedDemoTenant(): void
    {
        if (User::where('email', 'demo@daftar.test')->exists()) {
            return;
        }

        $tenant = app(TenantProvisioner::class)->create('متجر النور', Plan::where('slug', 'pro')->first());
        $tenant->update(['subscription_ends_at' => now()->addYear()]);

        User::create([
            'tenant_id' => $tenant->id,
            'role' => 'owner',
            'name' => 'صاحب المتجر',
            'email' => 'demo@daftar.test',
            'password' => 'password',
        ])->forceFill(['email_verified_at' => now()])->save();

        app(TenantContext::class)->runAs($tenant, function () {
            $ledger = app(LedgerService::class);
            $customers = Category::where('is_default', true)->orderBy('sort_order')->first();
            $currency = fn (string $code) => Currency::where('code', $code)->first();
            $ils = $currency('شيكل');
            $usd = $currency('دولار');
            $at = fn (string $date) => CarbonImmutable::parse($date);

            $account = fn (string $name, ?string $phone = null) => Account::create([
                'category_id' => $customers->id,
                'name' => $name,
                'phone_code' => '970',
                'phone' => $phone,
                'notes' => 'عميل تجريبي',
            ]);

            $othman = $account('عثمان ظهير', '599123456');
            $ledger->record($othman, 'credit', 2000, $usd, null, $at('2026-07-07 06:00'));
            $ledger->record($othman, 'debit', 200, $usd, null, $at('2026-09-24 12:08'));

            $nana = $account('نانا');
            $ledger->record($nana, 'debit', 1801, $ils, 'بضاعة', $at('2026-09-24 09:00'));

            $hamdi = $account('حمدي الجنجي', '598765432');
            $ledger->record($hamdi, 'credit', 3000, $ils, null, $at('2026-06-09 19:59'));
            $ledger->record($hamdi, 'debit', 1000, $ils, null, $at('2026-06-09 19:59'));
            $ledger->record($hamdi, 'debit', 1000, $ils, 'على حساب محمود خالد', $at('2026-06-14 18:26'));
            $ledger->record($hamdi, 'debit', 700, $ils, null, $at('2026-08-14 23:07'));
            $ledger->record($hamdi, 'debit', 300, $ils, null, $at('2026-09-10 21:28'));

            $khamis = $account('خميس زعرب');
            $ledger->record($khamis, 'credit', 8663, $ils, 'دفعة مقدمة', $at('2026-08-01 10:00'));

            foreach (['ابو نعنع' => 2000, 'اسماعيل العاجز' => 17515, 'بلال النمس' => 2250, 'حمدان حجازي' => 7000] as $name => $amount) {
                $ledger->record($account($name), 'credit', $amount, $ils, null, $at('2026-07-15 12:00'));
            }
        });
    }
}
