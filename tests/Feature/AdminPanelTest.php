<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password', 'is_active' => true]);
        $this->tenant = Tenant::create(['name' => 'متجر', 'slug' => 'store', 'plan_id' => Plan::where('slug', 'free')->value('id'), 'status' => Tenant::STATUS_ACTIVE]);
        User::create(['tenant_id' => $this->tenant->id, 'role' => 'owner', 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'password']);
    }

    public function test_admin_can_log_in(): void
    {
        $this->get(route('admin.login'))->assertOk()->assertSee('لوحة المشرف العام');

        $this->post(route('admin.login.submit'), ['email' => 'admin@example.test', 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($this->admin, 'admin');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->from(route('admin.login'))
            ->post(route('admin.login.submit'), ['email' => 'admin@example.test', 'password' => 'wrong'])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_admin_pages_render(): void
    {
        $this->actingAs($this->admin, 'admin');

        $this->get(route('admin.dashboard'))->assertOk()->assertSee('متجر');
        $this->get(route('admin.tenants.index', ['q' => 'متجر']))->assertOk()->assertSee('owner@example.test');
        $this->get(route('admin.tenants.show', $this->tenant))->assertOk()->assertSee('Owner');
        $this->get(route('admin.plans.index'))->assertOk()->assertSee('الاحترافية');
    }

    public function test_admin_can_upgrade_and_suspend_a_tenant(): void
    {
        $this->actingAs($this->admin, 'admin');
        $pro = Plan::where('slug', 'pro')->first();

        $this->put(route('admin.tenants.update', $this->tenant), [
            'name' => 'متجر', 'plan_id' => $pro->id, 'status' => 'active', 'extend_months' => 12,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($this->tenant->fresh()->isPro());

        $this->patch(route('admin.tenants.status', $this->tenant));
        $this->assertFalse($this->tenant->fresh()->isActive());
    }

    public function test_admin_can_update_a_plan(): void
    {
        $this->actingAs($this->admin, 'admin');
        $plan = Plan::where('slug', 'free')->first();

        $this->put(route('admin.plans.update', $plan), [
            'name' => 'المجانية', 'price_monthly' => 0, 'price_yearly' => 0,
            'max_accounts' => 50, 'max_transactions' => '', 'is_active' => 1,
            'features_text' => "ميزة 1\n\nميزة 2",
        ])->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame(50, $plan->max_accounts);
        $this->assertNull($plan->max_transactions);
        $this->assertSame(['ميزة 1', 'ميزة 2'], $plan->features);
    }
}
