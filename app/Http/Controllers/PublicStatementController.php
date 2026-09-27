<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PaymentReport;
use App\Models\StatementDispute;
use App\Services\ActivityLogger;
use App\Services\CurrencyConverter;
use App\Services\LedgerService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * The live statement a shop shares with its customer through a secret link.
 * No login: the unguessable token is the credential.
 */
class PublicStatementController extends Controller
{
    /** Movements shown on the page, newest first. */
    private const LIMIT = 300;

    public function __construct(private TenantContext $context) {}

    public function show(Request $request, string $token, LedgerService $ledger, CurrencyConverter $converter): Response
    {
        $account = $this->resolve($token);

        if (! $account) {
            return $this->unavailable();
        }

        return $this->context->runAs($account->tenant, function () use ($request, $account, $ledger, $converter) {
            // Don't count the shop's own staff previewing the link as a customer visit.
            if ($request->user()?->tenant_id !== $account->tenant_id) {
                $account->forceFill(['statement_viewed_at' => now()])->saveQuietly();
            }

            $balances = array_filter($ledger->accountBalances($account), fn ($b) => round($b, 4) != 0.0);
            $transactions = $ledger->statement($account);

            return $this->private(response()->view('statement.show', [
                'account' => $account,
                'tenant' => $account->tenant,
                'balances' => collect($balances)->map(fn ($balance, $id) => ['currency' => $converter->find($id), 'balance' => $balance]),
                'base' => $converter->base(),
                'total' => $converter->sumToBase($balances),
                'transactions' => $transactions->take(self::LIMIT),
                'hiddenCount' => max(0, $transactions->count() - self::LIMIT),
                'disputeTx' => $transactions->firstWhere('id', (int) $request->query('tx')),
                'unconfirmed' => $transactions->whereNull('confirmed_at')->count(),
                // Currencies the customer can report a payment in: the ones on their account, plus the base.
                'payCurrencies' => collect([$converter->base(), ...collect(array_keys($balances))->map(fn ($id) => $converter->find($id))])
                    ->filter()->unique('id')->values(),
                'methods' => PaymentReport::METHODS,
            ]));
        });
    }

    public function dispute(Request $request, string $token): RedirectResponse|Response
    {
        $account = $this->resolve($token);

        if (! $account) {
            return $this->unavailable();
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:3', 'max:1000'],
            'transaction_id' => ['nullable', 'integer', Rule::exists('transactions', 'id')->where('account_id', $account->id)->whereNull('deleted_at')],
        ], attributes: ['name' => 'الاسم', 'message' => 'نص الاعتراض', 'transaction_id' => 'الحركة']);

        $this->context->runAs($account->tenant, fn () => StatementDispute::create([
            ...$data,
            'account_id' => $account->id,
            'status' => StatementDispute::OPEN,
            'ip' => $request->ip(),
        ]));

        return redirect()->route('statement.show', $token)
            ->with('success', 'تم إرسال اعتراضك إلى '.$account->tenant->name.'، وسيتم مراجعته والتواصل معك.');
    }

    /**
     * The customer confirms one movement (transaction_id) or the whole statement as correct.
     */
    public function confirm(Request $request, string $token, ActivityLogger $logger): RedirectResponse|Response
    {
        $account = $this->resolve($token);

        if (! $account) {
            return $this->unavailable();
        }

        $request->validate(['transaction_id' => ['nullable', 'integer']]);

        $count = $this->context->runAs($account->tenant, function () use ($request, $account, $logger) {
            $query = $account->transactions()->whereNull('confirmed_at')->where('occurred_at', '<=', now());

            if ($request->filled('transaction_id')) {
                $query->whereKey($request->integer('transaction_id'));
            }

            $count = $query->update(['confirmed_at' => now()]);

            if ($count) {
                $logger->log('customer.confirmed', $request->filled('transaction_id')
                    ? "أكّد {$account->name} حركة من كشف حسابه"
                    : "أكّد {$account->name} صحة كشف حسابه ({$count} حركة)", $account, ['ip' => $request->ip()]);
            }

            return $count;
        });

        return redirect()->route('statement.show', $token)
            ->with('success', $count ? 'شكراً، تم تسجيل تأكيدك لدى '.$account->tenant->name.'.' : 'الحركات مؤكدة مسبقاً.');
    }

    /**
     * «أبلغ عن دفعة»: the customer reports a transfer / wallet payment; the shop approves it.
     */
    public function reportPayment(Request $request, string $token, CurrencyConverter $converter, ActivityLogger $logger): RedirectResponse|Response
    {
        $account = $this->resolve($token);

        if (! $account) {
            return $this->unavailable();
        }

        return $this->context->runAs($account->tenant, function () use ($request, $account, $token, $converter, $logger) {
            $data = $request->validate([
                'amount' => ['required', 'numeric', 'gt:0', 'max:100000000'],
                'currency_id' => ['required', 'integer', Rule::in($converter->currencies()->keys()->all())],
                'method' => ['required', Rule::in(array_keys(PaymentReport::METHODS))],
                'reference' => ['nullable', 'string', 'max:120'],
                'payer_name' => ['nullable', 'string', 'max:120'],
                'notes' => ['nullable', 'string', 'max:500'],
                'receipt' => ['nullable', 'image', 'max:5120'],
            ], attributes: ['amount' => 'المبلغ', 'currency_id' => 'العملة', 'method' => 'طريقة الدفع', 'receipt' => 'صورة الإيصال']);

            // A few pending reports at most per account keeps the shop's queue clean (and limits abuse).
            if ($account->paymentReports()->where('status', PaymentReport::PENDING)->count() >= 3) {
                return back()->withErrors(['amount' => 'لديك دفعات بانتظار المراجعة، يرجى الانتظار حتى يؤكدها المتجر.'])->withInput();
            }

            $report = PaymentReport::create([
                ...collect($data)->except('receipt')->all(),
                'account_id' => $account->id,
                'receipt_path' => $request->file('receipt')?->store('attachments/'.$account->tenant_id.'/payment-reports', 'local'),
                'status' => PaymentReport::PENDING,
                'ip' => $request->ip(),
            ]);

            $currency = $converter->find((int) $data['currency_id']);
            $logger->log('payment_report.created', "أبلغ {$account->name} عن دفعة {$currency->format($report->amount)} {$currency->code} ({$report->methodLabel()})", $report);

            return redirect()->route('statement.show', $token)
                ->with('success', 'تم إرسال بلاغ الدفعة إلى '.$account->tenant->name.'، وسيظهر في كشفك بعد تأكيدها.');
        });
    }

    /** The account behind a live token, or null when the link is invalid or switched off. */
    private function resolve(string $token): ?Account
    {
        if (strlen($token) < 32) {
            return null;
        }

        $account = Account::withoutGlobalScope('tenant')->with('tenant.plan')->where('statement_token', $token)->first();
        $tenant = $account?->tenant;

        return $tenant && $tenant->isActive() && $tenant->canUse('live_statement') ? $account : null;
    }

    private function unavailable(): Response
    {
        return $this->private(response()->view('statement.unavailable', status: 404));
    }

    /** Keep statements out of search engines, caches and referrer headers. */
    private function private(Response $response): Response
    {
        return $response->withHeaders([
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
