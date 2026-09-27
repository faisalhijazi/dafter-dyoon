<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\BackupLog;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Transaction;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(): View
    {
        $plans = Plan::withCount('tenants')->orderBy('sort_order')->get();

        $paidTenants = Tenant::whereHas('plan', fn ($q) => $q->where('price_monthly', '>', 0))
            ->where(fn ($q) => $q->whereNull('subscription_ends_at')->orWhere('subscription_ends_at', '>', now()))
            ->with('plan')
            ->get();

        return view('admin.dashboard', [
            'stats' => [
                'tenants' => Tenant::count(),
                'active' => Tenant::where('status', Tenant::STATUS_ACTIVE)->count(),
                'suspended' => Tenant::where('status', Tenant::STATUS_SUSPENDED)->count(),
                'pro' => $paidTenants->count(),
                'mrr' => $paidTenants->sum(fn (Tenant $t) => (float) $t->plan->price_monthly),
                'accounts' => Account::withoutGlobalScope('tenant')->count(),
                'transactions' => Transaction::withoutGlobalScope('tenant')->count(),
                'transactions_30d' => Transaction::withoutGlobalScope('tenant')->where('created_at', '>=', now()->subDays(30))->count(),
                'backups_30d' => BackupLog::withoutGlobalScope('tenant')->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'plans' => $plans,
            'recentTenants' => Tenant::with('plan', 'owner')->withCount('accounts')->latest()->take(8)->get(),
            'expiring' => Tenant::with('plan')
                ->whereBetween('subscription_ends_at', [now(), now()->addDays(14)])
                ->orderBy('subscription_ends_at')->take(8)->get(),
        ]);
    }
}
