<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creates a tenant with its default categories and regional currencies, and deletes one for good.
 */
class TenantProvisioner
{
    /** Default currencies; rates are how many ILS equal one unit (editable by the tenant). */
    public const DEFAULT_CURRENCIES = [
        ['code' => 'شيكل', 'iso_code' => 'ILS', 'name' => 'شيكل إسرائيلي', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_base' => true],
        ['code' => 'دولار', 'iso_code' => 'USD', 'name' => 'دولار أمريكي', 'exchange_rate' => 3.7, 'decimal_places' => 2],
        ['code' => 'دينار', 'iso_code' => 'JOD', 'name' => 'دينار أردني', 'exchange_rate' => 5.2, 'decimal_places' => 2],
        ['code' => 'سعودي', 'iso_code' => 'SAR', 'name' => 'ريال سعودي', 'exchange_rate' => 0.98, 'decimal_places' => 2],
        ['code' => 'مصري', 'iso_code' => 'EGP', 'name' => 'جنيه مصري', 'exchange_rate' => 0.075, 'decimal_places' => 2],
        ['code' => 'يمني', 'iso_code' => 'YER', 'name' => 'ريال يمني', 'exchange_rate' => 0.015, 'decimal_places' => 0],
    ];

    public function __construct(private TenantContext $context) {}

    /**
     * Permanently delete a tenant with its users, ledger data and uploaded attachments.
     * Rows go child-first because transactions restrict deleting the currencies they use.
     */
    public function delete(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant) {
            DB::table('statement_disputes')->where('tenant_id', $tenant->id)->delete();
            DB::table('transactions')->where('tenant_id', $tenant->id)->delete();
            $tenant->delete(); // cascades users, accounts, currencies, categories, backup logs
        });

        Storage::disk('local')->deleteDirectory('attachments/'.$tenant->id);
    }

    public function create(string $name, ?Plan $plan = null): Tenant
    {
        $tenant = Tenant::create([
            'name' => $name,
            'slug' => Str::slug($name) ?: 'tenant',
            'plan_id' => ($plan ?? Plan::where('slug', 'free')->first())?->id,
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $tenant->update(['slug' => $tenant->slug.'-'.$tenant->id]);

        $this->context->runAs($tenant, fn () => $this->seedDefaults($tenant));

        return $tenant;
    }

    public function seedDefaults(Tenant $tenant): void
    {
        Category::create(['tenant_id' => $tenant->id, 'name' => 'العملاء', 'color' => 'amber', 'is_default' => true, 'sort_order' => 1]);
        Category::create(['tenant_id' => $tenant->id, 'name' => 'الموردين', 'color' => 'cyan', 'is_default' => true, 'sort_order' => 2]);

        foreach (self::DEFAULT_CURRENCIES as $i => $currency) {
            Currency::create(['tenant_id' => $tenant->id, 'sort_order' => $i, 'is_base' => false, ...$currency]);
        }
    }
}
