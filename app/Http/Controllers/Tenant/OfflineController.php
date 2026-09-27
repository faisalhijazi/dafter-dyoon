<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Offline entry. The service worker caches `page()` (with a snapshot of accounts and currencies)
 * and shows it when there is no connection; entries queue in the browser (IndexedDB) and are
 * posted to `sync()` once back online. Each entry carries a client UUID, so retries never
 * record twice.
 */
class OfflineController extends Controller
{
    public const MAX_BATCH = 200;

    public function page(CurrencyConverter $converter): Response
    {
        return response()->view('tenant.offline', [
            'accounts' => Account::query()->orderBy('name')->get(['id', 'name', 'phone'])
                ->map(fn (Account $a) => ['id' => $a->id, 'name' => $a->name, 'phone' => $a->phone])->values(),
            'currencies' => $converter->usable()->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'base' => $c->is_base])->values(),
            'snapshotAt' => now(),
        ])->header('Cache-Control', 'private, no-cache');
    }

    public function sync(Request $request, LedgerService $ledger, CurrencyConverter $converter): JsonResponse
    {
        $entries = $request->validate([
            'entries' => ['required', 'array', 'max:'.self::MAX_BATCH],
            'entries.*' => ['array'],
        ])['entries'];

        $tenant = $request->user()->tenant;
        $usable = $converter->usable()->keyBy('id');
        $results = [];

        foreach ($entries as $entry) {
            $uuid = is_string($entry['uuid'] ?? null) ? $entry['uuid'] : null;

            $validator = Validator::make($entry, [
                'uuid' => ['required', 'uuid'],
                'account_id' => ['required', 'integer'],
                'type' => ['required', 'in:'.Transaction::CREDIT.','.Transaction::DEBIT],
                'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
                'currency_id' => ['required', 'integer'],
                'notes' => ['nullable', 'string', 'max:500'],
                'occurred_at' => ['required', 'date', 'before:'.now()->addDay()->toDateTimeString()],
            ], attributes: [
                'uuid' => 'معرّف المعاملة', 'account_id' => 'الحساب', 'type' => 'النوع', 'amount' => 'المبلغ',
                'currency_id' => 'العملة', 'notes' => 'الملاحظات', 'occurred_at' => 'التاريخ',
            ]);

            if ($validator->fails()) {
                $results[] = ['uuid' => $uuid, 'status' => 'rejected', 'message' => $validator->errors()->first()];

                continue;
            }

            if (Transaction::withTrashed()->where('client_uuid', $entry['uuid'])->exists()) {
                $results[] = ['uuid' => $uuid, 'status' => 'duplicate'];

                continue;
            }

            $account = Account::query()->find($entry['account_id']);
            $currency = $usable->get((int) $entry['currency_id']);

            if (! $account || ! $currency) {
                $results[] = ['uuid' => $uuid, 'status' => 'rejected', 'message' => ! $account ? 'الحساب لم يعد موجوداً.' : 'العملة غير متاحة في خطتك.'];

                continue;
            }

            if (! $tenant->withinLimit('transactions')) {
                $results[] = ['uuid' => $uuid, 'status' => 'rejected', 'message' => 'وصلت للحد الأقصى من المعاملات في خطتك.'];

                continue;
            }

            $ledger->record(
                $account,
                $entry['type'],
                (float) $entry['amount'],
                $currency,
                Str::limit(trim(($entry['notes'] ?? '').' (أُدخلت بدون إنترنت)'), 500, ''),
                CarbonImmutable::parse($entry['occurred_at']),
                clientUuid: $entry['uuid'],
            );

            $results[] = ['uuid' => $uuid, 'status' => 'saved'];
        }

        return response()->json(['results' => $results]);
    }
}
