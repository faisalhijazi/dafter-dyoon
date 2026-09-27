<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Admin;
use App\Models\Currency;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renders every page of the app against the seeded demo data and fails on any server error.
 */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_guest_pages_render(): void
    {
        $this->assertPagesRender(['/', '/login', '/register', '/forgot-password', '/reset-password/token', '/admin/login']);
    }

    public function test_tenant_pages_render(): void
    {
        $user = User::where('email', 'demo@daftar.test')->firstOrFail();
        $this->actingAs($user);

        $account = Account::withoutGlobalScope('tenant')->where('tenant_id', $user->tenant_id)->firstOrFail();
        $currency = Currency::withoutGlobalScope('tenant')->where('tenant_id', $user->tenant_id)->firstOrFail();

        $this->assertPagesRender([
            '/app', '/app/accounts/create', "/app/accounts/{$account->id}", "/app/accounts/{$account->id}/edit",
            '/app/quick-entry', '/app/transfer', '/app/split', '/app/statement',
            '/app/categories', '/app/currencies', '/app/currencies/create', "/app/currencies/{$currency->id}/edit",
            '/app/limits', '/app/backup', '/app/trash', '/app/preferences', '/app/upgrade',
            '/settings/profile',
        ]);

        $this->get('/settings/appearance')->assertRedirect('/app/preferences');

        // Security settings sit behind Fortify's password confirmation.
        $this->get('/settings/security')->assertRedirect('/user/confirm-password');
        $this->withSession(['auth.password_confirmed_at' => time()]);
        $this->assertPagesRender(['/settings/security', '/user/confirm-password']);
    }

    public function test_admin_pages_render(): void
    {
        $this->actingAs(Admin::firstOrFail(), 'admin');
        $tenant = Tenant::firstOrFail();

        $this->assertPagesRender(['/admin/dashboard', '/admin/tenants', "/admin/tenants/{$tenant->id}", '/admin/plans']);
    }

    private function assertPagesRender(array $urls): void
    {
        $failures = [];

        foreach ($urls as $url) {
            $response = $this->get($url);

            if ($response->getStatusCode() >= 500) {
                $failures[] = $url.' → '.$response->getStatusCode().': '.($response->exception?->getMessage() ?? '');
            } elseif ($response->isRedirect() || $response->getStatusCode() >= 400) {
                $failures[] = $url.' → '.$response->getStatusCode().' '.($response->headers->get('Location') ?? '');
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }
}
