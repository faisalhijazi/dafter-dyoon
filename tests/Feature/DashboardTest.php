<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantProvisioner;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $this->actingAs($this->tenantOwner());

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_users_without_a_tenant_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertForbidden();
    }

    public function test_suspended_tenants_see_the_suspended_page(): void
    {
        $user = $this->tenantOwner();
        $user->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertForbidden()->assertViewIs('tenant.suspended');
    }

    private function tenantOwner(): User
    {
        $this->seed(PlanSeeder::class);
        $tenant = app(TenantProvisioner::class)->create('متجر الاختبار');

        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
    }
}
