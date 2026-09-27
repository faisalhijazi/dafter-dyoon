<?php

namespace App\Services\Assistant;

use App\Models\Account;
use App\Models\AssistantMessage;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CurrencyConverter;
use App\Services\DebtLimitService;
use App\Services\LedgerService;
use App\Services\StatementMessage;
use App\Support\PhoneCodes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * «مساعد AI»: turns the NLU result into answers and ledger actions.
 *
 * Reads run immediately; money movements are returned as a "pending" action that
 * the merchant confirms with one tap. Every query goes through the tenant-scoped
 * models, so the assistant can only ever see the current tenant's data.
 *
 * @phpstan-type Reply array{text: string, intent: ?string, blocks: list<array<string, mixed>>, pending: ?array<string, mixed>, suggestions: list<string>, nlu?: array{masked_text: string, intent: string, source: string}|null}
 * @phpstan-type Data array<string, mixed>
 */
class Assistant
{
    /** Intent => [ledger type, wording, default category role]. */
    private const WRITES = [
        'add_debit' => [Transaction::DEBIT, 'عليه', 'customer'],
        'add_credit' => [Transaction::CREDIT, 'له', 'customer'],
        'add_supplier_invoice' => [Transaction::CREDIT, 'له', 'supplier'],
        'pay_supplier' => [Transaction::DEBIT, 'عليه', 'supplier'],
    ];

    private const WRITE_LABELS = [
        'add_debit' => 'تسجيل دين على الحساب',
        'add_credit' => 'تسجيل دفعة مقبوضة',
        'add_supplier_invoice' => 'تسجيل فاتورة مورد (دين علينا)',
        'pay_supplier' => 'تسجيل سداد للمورد',
    ];

    /** Choices offered by «ماذا قصدت؟» (intent => label). */
    public const INTENT_LABELS = [
        'add_debit' => 'تسجيل دين على زبون',
        'add_credit' => 'قبض دفعة من زبون',
        'add_supplier_invoice' => 'فاتورة مورد علينا',
        'pay_supplier' => 'سداد لمورد',
        'query_balance' => 'رصيد حساب',
        'query_last_transaction' => 'آخر حركة لحساب',
        'query_overdue' => 'الديون المتأخرة',
        'query_top_debtors' => 'أكبر المديونين',
        'query_threshold_alert' => 'سقوف الدين',
        'query_totals' => 'الموقف المالي الإجمالي',
        'query_currency_balance' => 'رصيد بعملة محددة',
        'query_exchange_rate' => 'سعر الصرف',
        'create_account' => 'فتح حساب جديد',
        'trigger_whatsapp' => 'رسالة واتساب',
    ];

    public const SUGGESTIONS = [
        'سجل 150 شيكل على أبو أحمد بضاعة',
        'قبضت 400 شيكل من محمد دفعة',
        'مين أكثر 5 زبائن عليهم ديون؟',
        'كم إجمالي الديون اللي لي بالسوق؟',
        'اعرضلي الديون المتأخرة أكثر من شهر',
        'كم سعر صرف الدولار؟',
    ];

    public function __construct(
        private PythonNlu $nlu,
        private LedgerService $ledger,
        private CurrencyConverter $converter,
        private DebtLimitService $limits,
        private StatementMessage $statement,
    ) {}

    private function tenant(): Tenant
    {
        $user = Auth::guard('web')->user();
        abort_unless($user instanceof User && $user->tenant, 403);

        return $user->tenant;
    }

    // =====================================================================================
    // Entry points
    // =====================================================================================

    /**
     * @param  string|null  $forceIntent  set when the merchant answered «ماذا قصدت؟»
     * @return Reply the reply, plus `nlu` (masked text, intent, source) for learning
     */
    public function handle(string $text, ?AssistantMessage $awaiting = null, ?string $forceIntent = null): array
    {
        try {
            $parsed = $this->nlu->parse($text, $forceIntent);
        } catch (RuntimeException $e) {
            report($e);

            return $this->reply('عذراً، محرك المساعد غير متاح حالياً. تأكد من تثبيت Python على الخادم ثم حاول مجدداً.');
        }

        $reply = $this->dispatch($parsed, $awaiting);

        // "السلام عليكم، كم على رامي؟" -> answer the greeting, then the command.
        if ($parsed['intent'] !== 'smalltalk' && ($prefix = $this->greetingPrefix($parsed['smalltalk']['keys'] ?? []))) {
            $reply['text'] = $prefix.' '.$reply['text'];
        }
        $reply['nlu'] = [
            'masked_text' => (string) ($parsed['masked_text'] ?? ''),
            'intent' => (string) $parsed['intent'],
            'source' => (string) ($parsed['source'] ?? 'model'),
        ];

        return $reply;
    }

    /**
     * @param  Data  $parsed
     * @return Reply
     */
    private function dispatch(array $parsed, ?AssistantMessage $awaiting): array
    {
        $intent = $parsed['intent'];
        $e = $parsed['entities'];

        // Follow-up: the previous answer asked for an amount and the merchant typed just that.
        if ($awaiting && ($pending = $awaiting->pending()) && ($pending['awaiting'] ?? null) === 'amount'
            && $e['amount'] && ! in_array($intent, array_keys(self::WRITES), true) && empty($e['account'])) {
            return $this->continueWrite([...$pending, 'amount' => $e['amount'], 'currency_id' => $e['currency_id'] ?? $pending['currency_id']]);
        }

        return match ($intent) {
            'add_debit', 'add_credit', 'add_supplier_invoice', 'pay_supplier' => $this->startWrite($intent, $e),
            'query_balance' => $this->withAccount($e, fn (Account $a) => $this->balance($a), 'query_balance'),
            'query_last_transaction' => $this->withAccount($e, fn (Account $a) => $this->lastTransaction($a, $e['payments_only']), 'query_last_transaction'),
            'query_overdue' => $this->overdue((int) ($e['days'] ?: 30), $e['category_id']),
            'query_top_debtors' => $this->topDebtors((int) ($e['limit'] ?: 5), $e['category_id']),
            'query_threshold_alert' => $this->thresholds($e),
            'query_totals' => $this->totals($e['totals_type'], $e['category_id'], $e['category_role']),
            'query_currency_balance' => $this->currencyBalance($e),
            'query_exchange_rate' => $this->exchangeRate($e['currency_id']),
            'create_account' => $this->createAccount($e),
            'trigger_whatsapp' => $this->withAccount($e, fn (Account $a) => $this->whatsapp($a), 'trigger_whatsapp'),
            'smalltalk' => $this->smalltalk($parsed['smalltalk']['keys'] ?? []),
            'greeting' => $this->smalltalk(['hello']),
            'help' => $this->help(),
            default => $this->unknown($e),
        };
    }

    /**
     * The merchant tapped «تأكيد» on a pending action.
     *
     * @param  Data  $pending
     * @return Reply
     */
    public function confirm(array $pending): array
    {
        return match ($pending['action'] ?? null) {
            'record' => $this->executeWrite($pending),
            'create_account' => $this->executeCreate($pending),
            default => $this->reply('لا توجد عملية بانتظار التأكيد.'),
        };
    }

    /**
     * The merchant picked an account (or "new") from a list of candidates.
     *
     * @param  Data  $pending
     * @return Reply
     */
    public function choose(array $pending, string $choice): array
    {
        if ($choice === 'new') {
            return $this->createAccount([
                'name_text' => $pending['name_text'] ?? null,
                'category_role' => $pending['role'] ?? 'customer',
                'category_id' => null,
                'phone' => null,
            ], $pending['then'] ?? null);
        }

        $account = Account::query()->find((int) $choice);

        if (! $account) {
            return $this->reply('لم أجد هذا الحساب.');
        }

        return match ($pending['then']['type'] ?? null) {
            'write' => $this->continueWrite([...$pending['then'], 'account_id' => $account->id]),
            'query_balance' => $this->balance($account),
            'query_last_transaction' => $this->lastTransaction($account, (bool) ($pending['then']['payments_only'] ?? false)),
            'trigger_whatsapp' => $this->whatsapp($account),
            'query_threshold_alert' => $this->accountThreshold($account),
            'query_currency_balance' => $this->accountCurrencyBalance($account, $this->converter->find((int) ($pending['then']['currency_id'] ?? 0))),
            default => $this->balance($account),
        };
    }

    // =====================================================================================
    // Writes: add_debit / add_credit / add_supplier_invoice / pay_supplier
    // =====================================================================================

    /**
     * @param  Data  $e
     * @return Reply
     */
    private function startWrite(string $intent, array $e): array
    {
        $state = [
            'type' => 'write',
            'intent' => $intent,
            'amount' => $e['amount'],
            'currency_id' => $e['currency_id'],
            'notes' => $e['notes'],
        ];

        $account = $this->resolveAccount($e, $state, self::WRITES[$intent][2]);

        if (is_array($account)) {
            return $account; // a clarification reply
        }

        return $this->continueWrite([...$state, 'account_id' => $account->id]);
    }

    /**
     * @param  Data  $state
     * @return Reply
     */
    private function continueWrite(array $state): array
    {
        $account = Account::query()->find((int) ($state['account_id'] ?? 0));
        [$type, $word] = self::WRITES[$state['intent']];

        if (! $account) {
            return $this->reply('لم أجد الحساب المطلوب.');
        }

        if (! $state['amount'] || $state['amount'] <= 0) {
            return $this->reply("كم المبلغ الذي تريد تسجيله {$word} {$account->name}؟", $state['intent'], pending: [...$state, 'awaiting' => 'amount']);
        }

        $currency = $this->currencyFor($account, $state['currency_id'] ?? null);

        if (! $this->tenant()->canUseCurrency($currency)) {
            $free = $this->converter->usable()->pluck('name')->implode(' و');

            return $this->reply("خطتك الحالية تشمل {$free} فقط. قم بالترقية للخطة الاحترافية لاستخدام {$currency->name}.", $state['intent'],
                [$this->linkBlock('عرض خطط الاشتراك', route('tenant.upgrade'))]);
        }

        if (! $this->tenant()->withinLimit('transactions')) {
            return $this->reply('وصلت للحد الأقصى من المعاملات في خطتك الحالية. قم بالترقية للمتابعة.', $state['intent'],
                [$this->linkBlock('الترقية', route('tenant.upgrade'))]);
        }

        $baseBalance = $this->ledger->accountBaseBalance($account);
        $warning = null;

        if ($type === Transaction::DEBIT && $this->limits->wouldExceed($account, $this->tenant(), $baseBalance, $this->converter->toBase($state['amount'], $currency))) {
            $limit = $this->limits->limitFor($account, $this->tenant());
            $warning = '⚠️ هذه العملية ستتجاوز سقف الدين لهذا الحساب ('.number_format($limit, 2).' '.$this->converter->base()->code.').';
        }

        $pending = [
            'action' => 'record',
            'intent' => $state['intent'],
            'account_id' => $account->id,
            'type' => $type,
            'amount' => $state['amount'],
            'currency_id' => $currency->id,
            'notes' => $state['notes'] ?? null,
        ];

        return $this->reply(
            'تأكد من التفاصيل ثم اضغط «تأكيد» للحفظ:',
            $state['intent'],
            [[
                'type' => 'confirm',
                'title' => self::WRITE_LABELS[$state['intent']],
                'tone' => $type === Transaction::CREDIT ? 'credit' : 'debit',
                'rows' => array_values(array_filter([
                    ['الحساب', $account->name],
                    ['النوع', $word.($type === Transaction::CREDIT ? ' (دائن)' : ' (مدين)')],
                    ['المبلغ', $currency->format($state['amount']).' '.$currency->code],
                    ! empty($state['notes']) ? ['الملاحظات', $state['notes']] : null,
                    ['الرصيد الحالي', $this->describeBase($baseBalance)],
                ])),
                'warning' => $warning,
            ]],
            pending: $pending,
        );
    }

    /**
     * @param  Data  $p
     * @return Reply
     */
    private function executeWrite(array $p): array
    {
        $account = Account::query()->find((int) ($p['account_id'] ?? 0));
        $currency = $this->converter->find((int) ($p['currency_id'] ?? 0));

        if (! $account || ! $currency || ! in_array($p['type'] ?? '', [Transaction::CREDIT, Transaction::DEBIT], true) || ($p['amount'] ?? 0) <= 0) {
            return $this->reply('تعذر تنفيذ العملية، البيانات غير مكتملة.');
        }

        if (! $this->tenant()->withinLimit('transactions')) {
            return $this->reply('وصلت للحد الأقصى من المعاملات في خطتك الحالية.');
        }

        $this->ledger->record($account, $p['type'], (float) $p['amount'], $currency, $p['notes'] ?? null);
        $word = $p['type'] === Transaction::CREDIT ? 'له' : 'عليه';

        return $this->reply(
            "✅ تم التسجيل: {$word} {$currency->format($p['amount'])} {$currency->code} — {$account->name}.",
            $p['intent'] ?? 'record',
            [$this->accountBlock($account)],
        );
    }

    // =====================================================================================
    // Account resolution
    // =====================================================================================

    /**
     * Returns the Account, or a clarification reply (choices / not found).
     *
     * @param  Data  $e
     * @param  Data  $then
     * @return Account|Reply
     */
    private function resolveAccount(array $e, array $then, string $role = 'customer'): Account|array
    {
        $candidates = collect($e['account_candidates'] ?? []);
        $best = $candidates->first();
        $nameText = $e['name_text'] ?? null;

        if ($best && $best['match'] === 'exact') {
            $ties = $candidates->where('match', 'exact')->where('score', $best['score']);

            if ($ties->count() === 1 && ($account = Account::query()->find((int) $best['id']))) {
                return $account;
            }
        }

        if ($candidates->isNotEmpty()) {
            $options = $candidates->take(5)->map(fn ($c) => ['label' => $c['name'], 'value' => (string) $c['id']])->all();

            if ($nameText && (! $best || $best['match'] !== 'exact')) {
                $options[] = ['label' => '➕ حساب جديد باسم «'.$nameText.'»', 'value' => 'new'];
            }

            return $this->reply(
                $nameText && $best['match'] !== 'exact' ? "لم أجد «{$nameText}» بالضبط. هل تقصد أحد هذه الحسابات؟" : 'يوجد أكثر من حساب مطابق، اختر الحساب المقصود:',
                $then['intent'] ?? $then['type'] ?? null,
                [['type' => 'choices', 'options' => $options]],
                pending: ['awaiting' => 'account', 'then' => $then, 'name_text' => $nameText, 'role' => $role],
            );
        }

        if ($nameText) {
            return $this->reply(
                "لا يوجد حساب باسم «{$nameText}». هل تريد إنشاءه؟",
                $then['intent'] ?? $then['type'] ?? null,
                [['type' => 'choices', 'options' => [['label' => '➕ إنشاء حساب «'.$nameText.'»', 'value' => 'new']]]],
                pending: ['awaiting' => 'account', 'then' => $then, 'name_text' => $nameText, 'role' => $role],
            );
        }

        return $this->reply('لأي حساب؟ اكتب اسم الشخص أو الشركة ضمن الطلب، مثلاً: «سجل 150 شيكل على أبو أحمد».', $then['intent'] ?? null);
    }

    /**
     * @param  Data  $e
     * @return Reply
     */
    private function withAccount(array $e, callable $callback, string $intent): array
    {
        $account = $this->resolveAccount($e, ['type' => $intent, 'payments_only' => $e['payments_only'] ?? false, 'currency_id' => $e['currency_id'] ?? null]);

        return is_array($account) ? $account : $callback($account);
    }

    private function currencyFor(Account $account, ?int $currencyId): Currency
    {
        if ($currencyId && ($currency = $this->converter->find($currencyId))) {
            return $currency;
        }

        // No currency said: the one the account deals in most recently (if the plan allows it), else the base.
        $lastId = $account->transactions()->latest('occurred_at')->value('currency_id');

        return $this->converter->usable()->firstWhere('id', $lastId) ?? $this->converter->base();
    }

    // =====================================================================================
    // Queries
    // =====================================================================================

    /**
     * @return Reply
     */
    private function balance(Account $account): array
    {
        $total = $this->ledger->accountBaseBalance($account);
        $text = match (true) {
            round($total, 2) == 0.0 => "حساب {$account->name} متزن، لا يوجد رصيد مستحق.",
            $total < 0 => "على {$account->name} مبلغ ".$this->money(-$total).' (عليه).',
            default => "لـ {$account->name} عندك ".$this->money($total).' (له).',
        };

        return $this->reply($text, 'query_balance', [$this->accountBlock($account)]);
    }

    /**
     * @return Reply
     */
    private function lastTransaction(Account $account, bool $paymentsOnly): array
    {
        $query = $account->transactions()->with('currency')->latest('occurred_at')->latest('id');
        $last = (clone $query)->when($paymentsOnly, fn ($q) => $q->where('type', Transaction::CREDIT))->first();

        if (! $last) {
            return $this->reply($paymentsOnly ? "لم يسجَّل أي سداد من {$account->name} حتى الآن." : "لا توجد معاملات مع {$account->name} بعد.", 'query_last_transaction',
                [$this->accountBlock($account)]);
        }

        $label = $paymentsOnly ? 'آخر دفعة من' : 'آخر معاملة مع';
        $recent = $query->take(5)->get();

        return $this->reply(
            "{$label} {$account->name}: ".($last->isCredit() ? 'له ' : 'عليه ').$last->currency->format($last->amount).' '.$last->currency->code
                .' بتاريخ '.$last->occurred_at->format('Y/m/d').' ('.$last->occurred_at->diffForHumans().').'
                .(filled($last->notes) ? " — {$last->notes}" : ''),
            'query_last_transaction',
            [[
                'type' => 'list',
                'title' => 'آخر الحركات',
                'rows' => $recent->map(fn (Transaction $t) => [
                    'name' => $t->isCredit() ? 'له' : 'عليه',
                    'meta' => $t->occurred_at->format('Y/m/d').($t->notes ? ' — '.Str::limit($t->notes, 30) : ''),
                    'value' => $t->currency->format($t->amount).' '.$t->currency->code,
                    'tone' => $t->isCredit() ? 'credit' : 'debit',
                ])->all(),
                'link' => ['label' => 'فتح دفتر '.$account->name, 'url' => route('tenant.accounts.show', $account)],
            ]],
        );
    }

    /** @return Collection<int, DebtorRow> accounts that owe us, biggest first */
    private function debtors(?int $categoryId): Collection
    {
        $accounts = Account::query()->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))->get()->keyBy('id');
        $rows = [];

        foreach ($this->ledger->balances($accounts->keys()->all()) as $id => $currencies) {
            $owed = -$this->converter->sumToBase($currencies);

            if (isset($accounts[$id]) && $owed > 0.004) {
                $rows[] = new DebtorRow($accounts[$id], $owed);
            }
        }

        return collect($rows)->sortByDesc(fn (DebtorRow $r) => $r->owed)->values();
    }

    /**
     * @return Reply
     */
    private function topDebtors(int $limit, ?int $categoryId): array
    {
        $limit = max(1, min($limit, 20));
        $rows = $this->debtors($categoryId)->take($limit);

        if ($rows->isEmpty()) {
            return $this->reply('لا يوجد أي حساب عليه ديون حالياً 🎉', 'query_top_debtors');
        }

        return $this->reply(
            "أكبر {$rows->count()} مديونين عندك (بالعملة الأساسية):",
            'query_top_debtors',
            [$this->rankingBlock($rows, fn ($r) => $r->account->category?->name ?? '')],
        );
    }

    /**
     * @return Reply
     */
    private function overdue(int $days, ?int $categoryId): array
    {
        $days = max(1, $days);
        $cutoff = now()->subDays($days);

        $rows = $this->debtors($categoryId)->each(function (DebtorRow $r) {
            // Last payment received; if they never paid, count from their first debt.
            $since = $r->account->transactions()->where('type', Transaction::CREDIT)->max('occurred_at')
                ?? $r->account->transactions()->min('occurred_at');
            $r->since = $since ? CarbonImmutable::parse($since) : null;
        })->filter(fn (DebtorRow $r) => (bool) $r->since?->lt($cutoff))->sortBy(fn (DebtorRow $r) => $r->since)->values();

        if ($rows->isEmpty()) {
            return $this->reply("لا توجد ديون متأخرة أكثر من {$days} يوماً 👌", 'query_overdue');
        }

        return $this->reply(
            "{$rows->count()} حساب لم يسددوا منذ أكثر من {$days} يوماً، بإجمالي ".$this->money($rows->sum('owed')).':',
            'query_overdue',
            [$this->rankingBlock($rows, fn (DebtorRow $r) => 'آخر سداد: '.$r->since?->diffForHumans())],
        );
    }

    /**
     * @param  Data  $e
     * @return Reply
     */
    private function thresholds(array $e): array
    {
        if (! $this->tenant()->canUse('debt_limits')) {
            return $this->reply('سقوف التنبيه متاحة في الخطة الاحترافية. يمكنك ضبطها من صفحة «إدارة السقوف» بعد الترقية.', 'query_threshold_alert',
                [$this->linkBlock('عرض الخطط', route('tenant.upgrade'))]);
        }

        if (! empty($e['account_candidates']) || ! empty($e['name_text'])) {
            return $this->withAccount($e, fn (Account $a) => $this->accountThreshold($a), 'query_threshold_alert');
        }

        $rows = $this->debtors(null)
            ->each(fn (DebtorRow $r) => $r->status = $this->limits->status($r->account, $this->tenant(), -$r->owed))
            ->filter(fn (DebtorRow $r) => in_array($r->status['state'] ?? null, ['warning', 'exceeded'], true))
            ->sortByDesc(fn (DebtorRow $r) => $r->status['ratio'] ?? 0)
            ->values();

        if ($rows->isEmpty()) {
            return $this->reply('لا يوجد أي حساب تجاوز السقف أو اقترب منه ✅', 'query_threshold_alert',
                [$this->linkBlock('إدارة السقوف', route('tenant.limits'))]);
        }

        $exceeded = $rows->where('status.state', 'exceeded')->count();

        return $this->reply(
            "{$exceeded} حساب تجاوز السقف و ".($rows->count() - $exceeded).' حساب اقترب منه:',
            'query_threshold_alert',
            [[
                'type' => 'list',
                'title' => 'تنبيهات السقوف',
                'rows' => $rows->map(fn ($r) => [
                    'name' => $r->account->name,
                    'meta' => ($r->status['state'] === 'exceeded' ? '⛔ تجاوز' : '⚠️ قريب').' — السقف '.number_format($r->status['limit'], 2),
                    'value' => $this->money($r->owed),
                    'tone' => $r->status['state'] === 'exceeded' ? 'debit' : 'warning',
                    'url' => route('tenant.accounts.show', $r->account),
                ])->all(),
                'link' => ['label' => 'إدارة السقوف', 'url' => route('tenant.limits')],
            ]],
        );
    }

    /**
     * @return Reply
     */
    private function accountThreshold(Account $account): array
    {
        $status = $this->limits->status($account, $this->tenant(), $this->ledger->accountBaseBalance($account));

        $text = match ($status['state']) {
            'none' => "حساب {$account->name} مفتوح، لا يوجد سقف دين عليه.",
            'exceeded' => "⛔ {$account->name} تجاوز السقف: عليه ".$this->money($status['owed']).' من أصل سقف '.$this->money($status['limit']).'.',
            'warning' => "⚠️ {$account->name} قريب من السقف: ".round($status['ratio'] * 100).'% ('.$this->money($status['owed']).' من '.$this->money($status['limit']).').',
            default => "✅ {$account->name} ضمن الحد: ".$this->money($status['owed']).' من سقف '.$this->money($status['limit'])
                .' (المتبقي '.$this->money(max(0, $status['limit'] - $status['owed'])).').',
        };

        return $this->reply($text, 'query_threshold_alert', [$this->accountBlock($account)]);
    }

    /**
     * @return Reply
     */
    private function totals(string $type, ?int $categoryId, ?string $role): array
    {
        $ids = $categoryId ? Account::where('category_id', $categoryId)->pluck('id')->all() : null;
        $s = $this->ledger->summary($ids);
        $scope = $categoryId ? ' ('.Category::query()->find($categoryId)?->name.')' : '';

        $text = match ($type) {
            'receivable' => "إجمالي الديون التي لك بالسوق{$scope}: ".$this->money($s['receivable']).'.',
            'payable' => "إجمالي ما عليك{$scope}: ".$this->money($s['payable']).'.',
            'net' => "الصافي{$scope}: ".$this->money(abs($s['net'])).' '.($s['net'] >= 0 ? 'لك' : 'عليك').'.',
            default => "ملخص الموقف المالي{$scope}:",
        };

        return $this->reply($text, 'query_totals', [[
            'type' => 'stats',
            'items' => [
                ['label' => 'لك', 'value' => $this->money($s['receivable']), 'tone' => 'credit'],
                ['label' => 'عليك', 'value' => $this->money($s['payable']), 'tone' => 'debit'],
                ['label' => 'الصافي '.($s['net'] >= 0 ? '(لك)' : '(عليك)'), 'value' => $this->money(abs($s['net'])), 'tone' => $s['net'] >= 0 ? 'credit' : 'debit'],
            ],
            'note' => 'محسوبة بالعملة الأساسية حسب أسعار الصرف المسجلة.',
        ]]);
    }

    /**
     * @param  Data  $e
     * @return Reply
     */
    private function currencyBalance(array $e): array
    {
        $currency = $this->converter->find((int) ($e['currency_id'] ?? 0));

        if (! $currency) {
            return $this->reply('بأي عملة؟ مثلاً: «كم حساب محمد بالدولار؟»', 'query_currency_balance');
        }

        if (! empty($e['account_candidates']) || ! empty($e['name_text'])) {
            $account = $this->resolveAccount($e, ['type' => 'query_currency_balance', 'currency_id' => $currency->id]);

            return is_array($account) ? $account : $this->accountCurrencyBalance($account, $currency);
        }

        $receivable = 0.0;
        $payable = 0.0;
        foreach ($this->ledger->balances(null, currencyId: $currency->id) as $balances) {
            $b = $balances[$currency->id] ?? 0;
            $b < 0 ? $receivable -= $b : $payable += $b;
        }

        return $this->reply("أرصدة {$currency->name}:", 'query_currency_balance', [[
            'type' => 'stats',
            'items' => [
                ['label' => 'لك', 'value' => $currency->format($receivable).' '.$currency->code, 'tone' => 'credit'],
                ['label' => 'عليك', 'value' => $currency->format($payable).' '.$currency->code, 'tone' => 'debit'],
                ['label' => 'الصافي', 'value' => $currency->format(abs($receivable - $payable)).' '.$currency->code.' '.($receivable >= $payable ? 'لك' : 'عليك'), 'tone' => $receivable >= $payable ? 'credit' : 'debit'],
            ],
        ]]);
    }

    /**
     * @return Reply
     */
    private function accountCurrencyBalance(Account $account, ?Currency $currency): array
    {
        $currency ??= $this->converter->base();
        $b = $this->ledger->accountBalances($account)[$currency->id] ?? 0.0;

        $text = match (true) {
            round($b, 4) == 0.0 => "لا يوجد رصيد بال{$currency->name} في حساب {$account->name}.",
            $b < 0 => "على {$account->name} بال{$currency->name}: {$currency->format(-$b)} {$currency->code} (عليه).",
            default => "لـ {$account->name} بال{$currency->name}: {$currency->format($b)} {$currency->code} (له).",
        };

        return $this->reply($text, 'query_currency_balance', [$this->accountBlock($account)]);
    }

    /**
     * @return Reply
     */
    private function exchangeRate(?int $currencyId): array
    {
        $base = $this->converter->base();
        $currency = $currencyId ? $this->converter->find($currencyId) : null;

        if ($currency && ! $currency->is_base) {
            $rate = (float) $currency->exchange_rate;

            return $this->reply(
                "سعر {$currency->name} المسجّل: 1 {$currency->code} = ".$this->rate($rate)." {$base->code}"
                    .' (و 1 '.$base->code.' = '.$this->rate($rate > 0 ? 1 / $rate : 0).' '.$currency->code.').',
                'query_exchange_rate',
                [$this->linkBlock('تعديل أسعار الصرف', route('tenant.currencies'))],
            );
        }

        $rows = $this->converter->currencies()->reject->is_base->map(fn (Currency $c) => [
            'name' => $c->name,
            'meta' => '1 '.$c->code,
            'value' => $this->rate((float) $c->exchange_rate).' '.$base->code,
            'tone' => 'neutral',
        ])->values()->all();

        return $this->reply("أسعار الصرف المسجّلة مقابل {$base->name}:", 'query_exchange_rate', [[
            'type' => 'list', 'title' => 'أسعار الصرف', 'rows' => $rows,
            'link' => ['label' => 'إدارة العملات', 'url' => route('tenant.currencies')],
        ]]);
    }

    // =====================================================================================
    // create_account / trigger_whatsapp
    // =====================================================================================

    /**
     * @param  Data  $e
     * @param  Data|null  $then
     * @return Reply
     */
    private function createAccount(array $e, ?array $then = null): array
    {
        $name = trim((string) ($e['name_text'] ?? ''));

        if ($name === '' || mb_strlen($name) < 2) {
            return $this->reply('ما اسم الحساب الجديد؟ مثلاً: «ضيف عميل جديد باسم سمير جوال 0599000000».', 'create_account');
        }

        if ($existing = Account::where('name', $name)->first()) {
            return $then
                ? $this->choose(['then' => $then], (string) $existing->id)
                : $this->reply("يوجد حساب باسم «{$name}» مسبقاً.", 'create_account', [$this->accountBlock($existing)]);
        }

        $category = ($e['category_id'] ?? null) ? Category::query()->find((int) $e['category_id']) : null;
        $category ??= $this->defaultCategory($e['category_role'] ?? 'customer');
        [$code, $phone] = $this->splitPhone($e['phone'] ?? null);

        $pending = [
            'action' => 'create_account',
            'name' => $name,
            'category_id' => $category?->id,
            'phone_code' => $code,
            'phone' => $phone,
            'then' => $then,
        ];

        return $this->reply('سيتم إنشاء حساب جديد بهذه البيانات:', 'create_account', [[
            'type' => 'confirm',
            'title' => 'فتح حساب جديد',
            'tone' => 'neutral',
            'rows' => array_values(array_filter([
                ['الاسم', $name],
                ['القسم', $category?->name ?? '—'],
                $phone ? ['الجوال', '+'.$code.' '.$phone] : null,
            ])),
        ]], pending: $pending);
    }

    /**
     * @param  Data  $p
     * @return Reply
     */
    private function executeCreate(array $p): array
    {
        if (! $this->tenant()->withinLimit('accounts')) {
            return $this->reply('وصلت للحد الأقصى من الحسابات في خطتك الحالية. قم بالترقية لإضافة المزيد.', 'create_account',
                [$this->linkBlock('الترقية', route('tenant.upgrade'))]);
        }

        $category = Category::query()->find((int) ($p['category_id'] ?? 0)) ?? $this->defaultCategory('customer');

        $account = Account::firstOrCreate(['name' => $p['name']], [
            'category_id' => $category?->id,
            'phone_code' => $p['phone_code'] ?? '970',
            'phone' => $p['phone'] ?? null,
            'notes' => 'أُضيف عبر مساعد AI',
        ]);

        // Created as part of a money command: continue straight to its confirmation.
        if (($p['then']['type'] ?? null) === 'write') {
            $next = $this->continueWrite([...$p['then'], 'account_id' => $account->id]);
            $next['text'] = "✅ تم إنشاء حساب {$account->name}. ".$next['text'];

            return $next;
        }

        return $this->reply("✅ تم فتح حساب {$account->name} في قسم {$category?->name}.", 'create_account', [$this->accountBlock($account)]);
    }

    /**
     * @return Reply
     */
    private function whatsapp(Account $account): array
    {
        $text = $this->statement->text($account);
        $number = $account->whatsappNumber();

        return $this->reply(
            $number ? "جهزت رسالة كشف الحساب لـ {$account->name}. اضغط الزر لفتح واتساب:" : "جهزت الرسالة، لكن لا يوجد رقم جوال مسجّل لـ {$account->name}؛ سيطلب منك واتساب اختيار جهة الاتصال.",
            'trigger_whatsapp',
            [[
                'type' => 'whatsapp',
                'url' => 'https://wa.me/'.($number ?? '').'?text='.rawurlencode($text),
                'preview' => $text,
            ]],
        );
    }

    // =====================================================================================
    // help / unknown
    // =====================================================================================

    /** @return array<string, mixed>|null */
    private function smalltalkEntry(string $key): ?array
    {
        $entry = config("assistant_smalltalk.{$key}");

        return is_array($entry) && ! empty($entry['replies']) ? $entry : null;
    }

    /**
     * Reply to greetings / courtesies / general questions (config/assistant_smalltalk.php).
     * Several phrases in one message ("السلام عليكم كيف حالك") -> the earlier ones' short
     * prefixes, then a full reply to the last one.
     *
     * @param  list<string>  $keys
     * @return Reply
     */
    private function smalltalk(array $keys): array
    {
        $keys = array_values(array_filter($keys, fn ($k) => $this->smalltalkEntry($k) !== null)) ?: ['hello'];
        $last = array_pop($keys);
        $entry = $this->smalltalkEntry($last) ?? ['replies' => ['أهلاً وسهلاً 👋 كيف أقدر أساعدك؟']];

        $prefix = collect($keys)->map(fn ($k) => $this->smalltalkEntry($k)['prefix'] ?? null)->filter()->unique()->implode(' ');
        $text = trim($prefix.' '.$this->fillPlaceholders(Arr::random($entry['replies'])));

        $blocks = [];
        if (($link = $entry['link'] ?? null) && Route::has($link['route'])) {
            $blocks[] = $this->linkBlock($link['label'], route($link['route']));
        }

        return $this->reply($text, 'smalltalk', $blocks, suggestions: ! empty($entry['examples']) ? self::SUGGESTIONS : []);
    }

    /** @param  list<string>  $keys */
    private function greetingPrefix(array $keys): ?string
    {
        foreach ($keys as $key) {
            $entry = $this->smalltalkEntry($key);

            if (($entry['type'] ?? null) === 'greeting' && ! empty($entry['prefix'])) {
                return $this->fillPlaceholders($entry['prefix']);
            }
        }

        return null;
    }

    private function fillPlaceholders(string $text): string
    {
        $user = Auth::guard('web')->user();

        return strtr($text, [
            '{name}' => Str::before(trim((string) $user?->name), ' ') ?: 'صديقي',
            '{store}' => $this->tenant()->name,
            '{app}' => (string) config('app.name'),
            '{time}' => now()->hour < 12 ? 'صباح الخير' : 'مساء الخير',
        ]);
    }

    /**
     * @return Reply
     */
    private function help(): array
    {
        $groups = [
            ['📝 تسجيل القيود', ['سجل 150 شيكل على أبو أحمد بضاعة', 'قبضت 400 شيكل من محمد دفعة', 'وصلت بضاعة من شركة القدس بـ 1200 شيكل دين', 'دفعت لمورد الزيوت 500 شيكل']],
            ['🔎 الاستعلامات', ['كم باقي على رامي؟', 'شو آخر دفعة دفعها خليل؟', 'كم حساب محمد بالدولار لحاله؟']],
            ['📊 التقارير والرقابة', ['مين أكثر 3 زبائن عليهم ديون؟', 'مين ما سدد من 45 يوم؟', 'مين تجاوز سقف الدين؟', 'كم الصافي اللي علي للموردين؟']],
            ['⚙️ أخرى', ['كم سعر صرف الدولار؟', 'ضيف عميل جديد باسم سمير جوال 0599000000', 'جهز رسالة واتساب لحساب أبو أحمد']],
        ];

        return $this->reply('أفهم أوامرك بالعامية أو الفصحى. هذه أمثلة يمكنك الضغط عليها:', 'help', [[
            'type' => 'examples',
            'groups' => array_map(fn ($g) => ['title' => $g[0], 'items' => $g[1]], $groups),
        ]]);
    }

    /**
     * @param  Data  $e
     * @return Reply
     */
    private function unknown(array $e): array
    {
        // Just a name typed? Show that account.
        $best = $e['account_candidates'][0] ?? null;
        if ($best && $best['match'] === 'exact' && ($account = Account::query()->find((int) $best['id']))) {
            return $this->balance($account);
        }

        return $this->reply('لم أفهم الطلب تماماً 🤔 أنا متخصص في دفتر الديون: التسجيل، الأرصدة، التقارير والتنبيهات. جرّب مثلاً:', 'unknown', suggestions: array_slice(self::SUGGESTIONS, 0, 4));
    }

    // =====================================================================================
    // Helpers
    // =====================================================================================

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  Data|null  $pending
     * @param  list<string>  $suggestions
     * @return Reply
     */
    private function reply(string $text, ?string $intent = null, array $blocks = [], ?array $pending = null, array $suggestions = []): array
    {
        return compact('text', 'intent', 'blocks', 'pending', 'suggestions');
    }

    private function money(float $amount): string
    {
        $base = $this->converter->base();

        return $base->format($amount).' '.$base->code;
    }

    private function rate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 4, '.', ','), '0'), '.');
    }

    private function describeBase(float $balance): string
    {
        return match (true) {
            round($balance, 2) == 0.0 => 'متزن',
            $balance < 0 => $this->money(-$balance).' عليه',
            default => $this->money($balance).' له',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function accountBlock(Account $account): array
    {
        $balances = array_filter($this->ledger->accountBalances($account), fn ($b) => round($b, 4) != 0.0);
        $total = $this->converter->sumToBase($balances);
        $limit = $this->limits->status($account, $this->tenant(), $total);

        return [
            'type' => 'account',
            'account_id' => $account->id,
            'name' => $account->name,
            'category' => $account->category?->name,
            'balances' => collect($balances)->map(function ($b, $currencyId) {
                $c = $this->converter->find($currencyId);

                return ['amount' => $c->format(abs($b)).' '.$c->code, 'status' => $b > 0 ? 'له' : 'عليه', 'tone' => $b > 0 ? 'credit' : 'debit'];
            })->values()->all(),
            'total' => count($balances) > 1 ? $this->describeBase($total) : null,
            'limit' => in_array($limit['state'], ['warning', 'exceeded'], true) ? $limit['state'] : null,
            'url' => route('tenant.accounts.show', $account),
        ];
    }

    /**
     * @param  Collection<int, DebtorRow>  $rows
     * @param  callable(DebtorRow): string  $meta
     * @return array<string, mixed>
     */
    private function rankingBlock(Collection $rows, callable $meta): array
    {
        return [
            'type' => 'list',
            'title' => null,
            'rows' => $rows->values()->map(fn ($r, $i) => [
                'name' => ($i + 1).'. '.$r->account->name,
                'meta' => $meta($r),
                'value' => $this->money($r->owed),
                'tone' => 'debit',
                'url' => route('tenant.accounts.show', $r->account),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linkBlock(string $label, string $url): array
    {
        return ['type' => 'link', 'label' => $label, 'url' => $url];
    }

    private function defaultCategory(string $role): ?Category
    {
        $keyword = $role === 'supplier' ? 'مورد' : 'عملاء';

        return Category::where('name', 'like', "%{$keyword}%")->orderBy('sort_order')->first()
            ?? Category::orderBy('sort_order')->first();
    }

    /**
     * "0599000000" -> ['970', '599000000'], "+962791234567" -> ['962', '791234567'].
     *
     * @return array{0: string, 1: string|null}
     */
    private function splitPhone(?string $phone): array
    {
        if (! $phone) {
            return ['970', null];
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '+') || str_starts_with($digits, '00')) {
            $digits = ltrim($digits, '0');
            foreach (collect(array_keys(PhoneCodes::ALL))->map(fn ($c) => (string) $c)->sortByDesc(fn ($c) => strlen($c)) as $code) {
                if (str_starts_with($digits, $code)) {
                    return [$code, substr($digits, strlen($code))];
                }
            }
        }

        return ['970', ltrim($digits, '0')];
    }
}
