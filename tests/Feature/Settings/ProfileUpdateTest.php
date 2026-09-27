<?php

namespace Tests\Feature\Settings;

use App\Models\Account;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TenantProvisioner;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($this->tenantUser());

        $this->get(route('profile.edit'))->assertOk()->assertSee('الملف الشخصي');
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = $this->tenantUser();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = $this->tenantUser();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_owner_deleting_their_account_deletes_the_whole_tenant(): void
    {
        $this->seed();
        $owner = User::where('email', 'demo@daftar.test')->firstOrFail();
        $tenantId = $owner->tenant_id;
        $other = app(TenantProvisioner::class)->create('متجر آخر');

        $this->actingAs($owner);

        Livewire::test('pages::settings.profile')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertFalse(auth()->check());
        $this->assertNull($owner->fresh());
        $this->assertNull(Tenant::find($tenantId));
        $this->assertSame(0, Account::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->count());
        $this->assertSame(0, Transaction::withoutGlobalScope('tenant')->withTrashed()->where('tenant_id', $tenantId)->count());
        $this->assertNotNull($other->fresh(), 'other tenants must be untouched');
        $this->assertGreaterThan(0, $other->currencies()->count());
    }

    public function test_non_owner_deleting_their_account_keeps_the_tenant(): void
    {
        $owner = $this->tenantUser();
        $member = User::factory()->create(['tenant_id' => $owner->tenant_id, 'role' => 'member']);

        $this->actingAs($member);

        Livewire::test('pages::settings.profile')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors();

        $this->assertNull($member->fresh());
        $this->assertNotNull($owner->fresh());
        $this->assertNotNull(Tenant::find($owner->tenant_id));
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = $this->tenantUser();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $response->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }

    private function tenantUser(): User
    {
        $this->seed(PlanSeeder::class);
        $tenant = app(TenantProvisioner::class)->create('متجر الاختبار');

        return User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'owner']);
    }
}
