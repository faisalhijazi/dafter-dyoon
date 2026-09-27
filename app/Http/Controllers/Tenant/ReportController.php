<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Category;
use App\Models\PaymentReport;
use App\Services\CurrencyConverter;
use App\Services\LedgerService;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Printable pages (browser "print / save as PDF"): account statement, debt aging, monthly report.
 */
class ReportController extends Controller
{
    public function accountStatement(Request $request, Account $account, LedgerService $ledger, CurrencyConverter $converter): View
    {
        $from = $request->date('from');
        $to = $request->date('to');

        $transactions = $ledger->statement($account)
            ->filter(fn ($t) => (! $from || $t->occurred_at->gte($from->startOfDay())) && (! $to || $t->occurred_at->lte($to->endOfDay())))
            ->reverse()
            ->values();

        $balances = array_filter($ledger->accountBalances($account), fn ($b) => round($b, 4) != 0.0);

        return view('reports.account-statement', [
            'account' => $account->load('category'),
            'transactions' => $transactions,
            'balances' => collect($balances)->map(fn ($b, $id) => ['currency' => $converter->find($id), 'balance' => $b]),
            'total' => $converter->sumToBase($balances),
            'base' => $converter->base(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function aging(Request $request, ReportService $reports, CurrencyConverter $converter): View
    {
        $categoryId = $request->integer('category') ?: null;

        return view('reports.aging', [
            ...$reports->aging($categoryId),
            'categories' => Category::orderBy('sort_order')->get(),
            'categoryId' => $categoryId,
            'base' => $converter->base(),
            'buckets' => ReportService::AGING_BUCKETS,
        ]);
    }

    public function monthly(Request $request, ReportService $reports, CurrencyConverter $converter): View
    {
        $month = rescue(fn () => CarbonImmutable::createFromFormat('!Y-m', (string) $request->query('month')), null, false)
            ?? CarbonImmutable::now()->startOfMonth();

        return view('reports.monthly', [
            'report' => $reports->monthly($month),
            'months' => collect(range(0, 11))->map(fn ($i) => CarbonImmutable::now()->startOfMonth()->subMonths($i)),
            'base' => $converter->base(),
        ]);
    }

    /** Receipt photo a customer attached to a payment report (tenant-scoped binding). */
    public function receipt(PaymentReport $paymentReport): StreamedResponse
    {
        abort_unless($paymentReport->receipt_path && Storage::disk('local')->exists($paymentReport->receipt_path), 404);

        return Storage::disk('local')->response($paymentReport->receipt_path);
    }
}
