<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BackupLog;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Exports / restores all ledger data of one tenant as an encrypted, gzipped JSON payload.
 */
class BackupService
{
    public const VERSION = 1;

    public const EXTENSION = 'ddbak';

    public function __construct(private CurrencyConverter $converter) {}

    public function fileName(Tenant $tenant): string
    {
        return 'daftar-duyun-'.$tenant->slug.'-'.now()->format('Y-m-d_His').'.'.self::EXTENSION;
    }

    public function export(Tenant $tenant): string
    {
        $payload = [
            'version' => self::VERSION,
            'app' => config('app.name'),
            'created_at' => now()->toIso8601String(),
            'tenant' => [
                'name' => $tenant->name,
                'default_debt_limit' => $tenant->default_debt_limit,
                'settings' => $tenant->settings,
            ],
            'categories' => Category::query()->get()->map->getAttributes()->all(),
            'currencies' => Currency::query()->get()->map->getAttributes()->all(),
            'accounts' => Account::withTrashed()->get()->map->getAttributes()->all(),
            'transactions' => Transaction::withTrashed()->get()->map->getAttributes()->all(),
        ];

        return Crypt::encryptString(gzencode(json_encode($payload, JSON_UNESCAPED_UNICODE), 9));
    }

    /**
     * @return array{accounts: int, transactions: int}
     */
    public function restore(Tenant $tenant, string $contents): array
    {
        try {
            $data = json_decode(gzdecode(Crypt::decryptString(trim($contents))), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException|\ErrorException) {
            throw new RuntimeException('الملف غير صالح أو تالف، أو أنه أُنشئ من منصة أخرى.');
        }

        if (($data['version'] ?? null) !== self::VERSION || ! isset($data['accounts'], $data['currencies'])) {
            throw new RuntimeException('صيغة النسخة الاحتياطية غير مدعومة.');
        }

        DB::transaction(function () use ($tenant, $data) {
            Transaction::withTrashed()->forceDelete();
            Account::withTrashed()->forceDelete();
            Currency::query()->delete();
            Category::query()->delete();

            $tenant->update([
                'default_debt_limit' => $data['tenant']['default_debt_limit'] ?? null,
                'settings' => $data['tenant']['settings'] ?? $tenant->settings,
            ]);

            $categoryIds = $this->insertMapped('categories', $tenant, $data['categories'] ?? []);
            $currencyIds = $this->insertMapped('currencies', $tenant, $data['currencies']);
            // Keep customers' statement links working, unless another shop's account already holds the token.
            $takenTokens = Account::withoutGlobalScope('tenant')
                ->whereIn('statement_token', array_filter(array_column($data['accounts'], 'statement_token')))
                ->pluck('statement_token')->all();
            $data['accounts'] = array_map(fn ($row) => in_array($row['statement_token'] ?? null, $takenTokens, true)
                ? [...$row, 'statement_token' => null] : $row, $data['accounts']);

            $accountIds = $this->insertMapped('accounts', $tenant, $data['accounts'], [
                'category_id' => $categoryIds,
            ]);

            $rows = [];
            foreach ($data['transactions'] ?? [] as $row) {
                if (! isset($accountIds[$row['account_id']], $currencyIds[$row['currency_id']])) {
                    continue;
                }

                unset($row['id']);
                $rows[] = [
                    ...$row,
                    'tenant_id' => $tenant->id,
                    'account_id' => $accountIds[$row['account_id']],
                    'currency_id' => $currencyIds[$row['currency_id']],
                    'user_id' => null,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('transactions')->insert($chunk);
            }
        });

        $this->converter->flush();

        return ['accounts' => count($data['accounts']), 'transactions' => count($data['transactions'] ?? [])];
    }

    /**
     * Insert rows one by one (to learn their new ids) and return [old id => new id].
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, array<int, int>>  $foreignMaps
     * @return array<int, int>
     */
    private function insertMapped(string $table, Tenant $tenant, array $rows, array $foreignMaps = []): array
    {
        $map = [];

        foreach ($rows as $row) {
            $oldId = $row['id'];
            unset($row['id']);
            $row['tenant_id'] = $tenant->id;

            foreach ($foreignMaps as $column => $ids) {
                $row[$column] = isset($row[$column]) ? ($ids[$row[$column]] ?? null) : null;
            }

            $map[$oldId] = DB::table($table)->insertGetId($row);
        }

        return $map;
    }

    public function log(string $provider, string $action, string $fileName, int $size, string $status = 'completed', ?string $message = null, ?string $remoteId = null): BackupLog
    {
        return BackupLog::create([
            'user_id' => Auth::guard('web')->id(),
            'provider' => $provider,
            'action' => $action,
            'file_name' => $fileName,
            'remote_id' => $remoteId,
            'size_bytes' => $size,
            'status' => $status,
            'message' => $message,
        ]);
    }
}
