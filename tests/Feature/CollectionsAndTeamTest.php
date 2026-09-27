<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Currency;
use App\Models\PaymentPromise;
use App\Models\PaymentReport;
use App\Models\Plan;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CollectionsService;
use App\Services\CustomerScore;
use App\Services\LedgerService;
use App\Services\ReportService;
use App\Services\TenantProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Collections (due dates, promises, payment reports), pins & tags, reports, staff & activity log,
 * customer confirmation and offline sync.
 */
class CollectionsAndTeamTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $account;

    private Currency $ils;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(); // demo store on the paid plan
        $this->owner = User::where('email', 'demo@daftar.test')->firstOrFail();
        $this->actingAs($this->owner);
        $this->account = Account::create(['name' => 'زبون الاختبار', 'category_id' => Category::query()->value('id'), 'notes' => 'test', 'phone_code' => '970', 'phone' => '599000111']);
        $this->ils = Currency::where('iso_code', 'ILS')->firstOrFail();
    }

    private function record(string $type, float $amount, ?string $at = null): Transaction
    {
        return app(LedgerService::class)->record($this->account, $type, $amount, $this->ils, null, $at ? CarbonImmutable::parse($at) : null);
    }

    public function test_new_pages_render_for_the_owner(): void
    {
        $this->record(Transaction::DEBIT, 500, '-40 days');

        foreach ([
            route('tenant.collections'), route('tenant.insights'), route('tenant.reports.aging'),
            route('tenant.reports.monthly'), route('tenant.accounts.print', $this->account),
            route('tenant.team'), route('tenant.activity'), route('tenant.offline'),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(route('tenant.accounts.print', $this->account))->assertSee('كشف حساب: زبون الاختبار')->assertSee('طباعة / حفظ PDF');
    }

    public function test_pinned_accounts_come_first_on_the_dashboard(): void
    {
        Livewire::test('pages::tenant.account-ledger', ['account' => $this->account])->call('togglePin');

        $this->assertNotNull($this->account->fresh()->pinned_at);
        $first = Livewire::test('pages::tenant.dashboard')->instance()->accounts->first();
        $this->assertTrue($first->is($this->account));
    }

    public function test_tags_are_created_from_the_account_form_and_filter_the_dashboard(): void
    {
        Livewire::test('pages::tenant.account-form', ['account' => $this->account])
            ->set('newTag', 'جملة')->call('addTag')
            ->call('save');

        $tag = Tag::where('name', 'جملة')->firstOrFail();
        $this->assertTrue($this->account->fresh()->tags->contains($tag));

        $ids = Livewire::test('pages::tenant.dashboard')->call('setTag', $tag->id)->instance()->accounts->pluck('id')->all();
        $this->assertSame([$this->account->id], $ids);
    }

    public function test_due_dates_show_in_collections_and_roll_forward_after_payment(): void
    {
        $this->record(Transaction::DEBIT, 300);
        $this->account->update(['due_date' => today()->subDays(2), 'due_repeat' => 'monthly']);

        $due = app(CollectionsService::class)->dueAccounts();
        $this->assertSame('overdue', $due->firstWhere('account.id', $this->account->id)['state']);
        Livewire::test('pages::tenant.collections')->assertSee('زبون الاختبار')->assertSee('متأخر');

        $this->record(Transaction::CREDIT, 100); // a monthly due date moves to the next period
        $this->assertTrue($this->account->fresh()->due_date->isFuture());

        $this->account->update(['due_date' => today(), 'due_repeat' => 'none']);
        $this->record(Transaction::CREDIT, 200); // settled: a one-off due date is cleared
        $this->assertNull($this->account->fresh()->due_date);
    }

    public function test_promises_are_kept_by_a_payment_or_broken_when_the_day_passes(): void
    {
        $this->record(Transaction::DEBIT, 1000, '-10 days');

        Livewire::test('pages::tenant.account-ledger', ['account' => $this->account])
            ->call('openPromise')
            ->set('promiseDate', today()->addDay()->format('Y-m-d'))
            ->set('promiseAmount', '400')
            ->call('savePromise');

        $kept = PaymentPromise::firstOrFail();
        $this->record(Transaction::CREDIT, 400);
        $this->assertSame(PaymentPromise::KEPT, $kept->fresh()->status);

        $broken = PaymentPromise::create(['account_id' => $this->account->id, 'currency_id' => $this->ils->id, 'promised_on' => today()->subDay(), 'amount' => 100]);
        $this->artisan('collections:daily')->assertSuccessful();
        $this->assertSame(PaymentPromise::BROKEN, $broken->fresh()->status);

        $score = app(CustomerScore::class)->forAccounts([$this->account->fresh()])[$this->account->id];
        $this->assertContains('أخلف 1 وعد سداد', $score['reasons']);
        $this->assertContains('التزم بـ 1 وعد سداد', $score['reasons']);
    }

    public function test_aging_settles_the_oldest_debt_first(): void
    {
        $this->record(Transaction::DEBIT, 100, '-100 days');
        $this->record(Transaction::DEBIT, 200, '-45 days');
        $this->record(Transaction::DEBIT, 300, '-5 days');
        $this->record(Transaction::CREDIT, 150, '-2 days'); // pays the 100 (90+) and 50 of the 200

        $row = collect(app(ReportService::class)->aging()['rows'])->firstWhere('account.id', $this->account->id);

        $this->assertEquals(['0-30' => 300.0, '31-60' => 150.0, '61-90' => 0.0, '90+' => 0.0], $row['buckets']);
        $this->assertEquals(450.0, $row['total']);
    }

    public function test_activity_log_records_who_did_what(): void
    {
        $tx = $this->record(Transaction::DEBIT, 75);
        $tx->delete();

        $events = ActivityLog::where('subject_type', 'Transaction')->where('subject_id', $tx->id)->pluck('event')->all();
        $this->assertSame(['transaction.created', 'transaction.deleted'], $events);
        $this->assertSame($this->owner->id, ActivityLog::latest('id')->value('user_id'));

        Livewire::test('pages::tenant.activity')->assertSee('صاحب المتجر')->assertSee('حذف عملية');
    }

    public function test_owner_adds_staff_whose_permissions_are_enforced(): void
    {
        Livewire::test('pages::tenant.team')
            ->call('create')
            ->set('name', 'كاشير')->set('email', 'cashier@example.test')->set('password', 'secret-pass-1')
            ->set('permissions', [])
            ->call('save')
            ->assertHasNoErrors();

        $staff = User::where('email', 'cashier@example.test')->firstOrFail();
        $this->assertSame(User::ROLE_STAFF, $staff->role);
        $this->assertSame($this->owner->tenant_id, $staff->tenant_id);

        $this->actingAs($staff);
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('tenant.collections'))->assertOk();
        foreach (['tenant.insights', 'tenant.currencies', 'tenant.team', 'tenant.activity', 'tenant.trash'] as $route) {
            $this->get(route($route))->assertForbidden();
        }

        // Can record, cannot delete.
        $tx = $this->record(Transaction::DEBIT, 50);
        Livewire::test('pages::tenant.account-ledger', ['account' => $this->account])
            ->call('deleteTransaction', $tx->id)->assertForbidden();

        // Granting "reports" opens the insights page.
        $staff->update(['permissions' => ['reports']]);
        $this->get(route('tenant.insights'))->assertOk();

        // A paused staff member is logged out.
        $staff->update(['is_active' => false]);
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_free_plan_has_no_staff_seats(): void
    {
        $tenant = app(TenantProvisioner::class)->create('محل صغير', Plan::where('slug', 'free')->first());
        $owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($owner);

        $this->assertFalse($tenant->canAddStaff());
        Livewire::test('pages::tenant.team')->call('create')->assertRedirect(route('tenant.upgrade'));
    }

    public function test_customer_confirms_movements_and_reports_a_payment_from_the_statement(): void
    {
        Storage::fake('local');
        $tx = $this->record(Transaction::DEBIT, 900);
        $this->account->regenerateStatementToken();
        $token = $this->account->fresh()->statement_token;
        auth()->logout();

        $this->post(route('statement.confirm', $token), ['transaction_id' => $tx->id])->assertRedirect();
        $this->assertNotNull($tx->fresh()->confirmed_at);

        $this->post(route('statement.payment', $token), [
            'amount' => 250, 'currency_id' => $this->ils->id, 'method' => 'bank', 'reference' => 'TRX-77',
            'receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertRedirect()->assertSessionHas('success');

        $report = PaymentReport::withoutGlobalScope('tenant')->firstOrFail();
        $this->assertSame(PaymentReport::PENDING, $report->status);
        Storage::disk('local')->assertExists($report->receipt_path);

        // The shop approves it: recorded as a credit with the receipt attached.
        $this->actingAs($this->owner);
        Livewire::test('pages::tenant.collections')->set('tab', 'reports')->assertSee('TRX-77')->call('approve', $report->id);

        $credit = $this->account->transactions()->where('type', Transaction::CREDIT)->firstOrFail();
        $this->assertEquals(250, (float) $credit->amount);
        $this->assertSame($report->receipt_path, $credit->attachment_path);
        $this->assertSame(PaymentReport::APPROVED, $report->fresh()->status);
    }

    public function test_offline_entries_sync_once_even_when_retried(): void
    {
        $uuid = (string) Str::uuid();
        $entry = ['uuid' => $uuid, 'account_id' => $this->account->id, 'type' => 'debit', 'amount' => 42, 'currency_id' => $this->ils->id, 'notes' => 'بدون نت', 'occurred_at' => now()->subHour()->toIso8601String()];
        $bad = ['uuid' => (string) Str::uuid(), 'account_id' => 999999, 'type' => 'debit', 'amount' => 5, 'currency_id' => $this->ils->id, 'occurred_at' => now()->toIso8601String()];

        $this->postJson(route('tenant.offline.sync'), ['entries' => [$entry, $bad]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'saved')
            ->assertJsonPath('results.1.status', 'rejected');

        $this->postJson(route('tenant.offline.sync'), ['entries' => [$entry]])->assertJsonPath('results.0.status', 'duplicate');

        $this->assertSame(1, $this->account->transactions()->count());
        $this->assertSame($uuid, $this->account->transactions()->value('client_uuid'));
    }
}
