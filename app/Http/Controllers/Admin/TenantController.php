<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TenantController extends Controller
{
    public function index(Request $request): View
    {
        $tenants = Tenant::query()
            ->with(['plan', 'owner'])
            ->withCount(['accounts', 'transactions', 'users'])
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->q.'%')
                ->orWhereHas('users', fn ($u) => $u->where('email', 'like', '%'.$request->q.'%'))))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('plan'), fn ($q) => $q->where('plan_id', $request->plan))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.tenants.index', [
            'tenants' => $tenants,
            'plans' => Plan::orderBy('sort_order')->get(),
        ]);
    }

    public function show(Tenant $tenant): View
    {
        $tenant->load(['plan', 'users'])->loadCount(['accounts', 'transactions', 'currencies', 'categories']);

        return view('admin.tenants.show', [
            'tenant' => $tenant,
            'plans' => Plan::orderBy('sort_order')->get(),
            'backups' => $tenant->backupLogs()->latest()->take(10)->get(),
            'payments' => $tenant->subscriptionPayments()->latest()->take(6)->get(),
        ]);
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'plan_id' => ['required', Rule::exists('plans', 'id')],
            'status' => ['required', Rule::in([Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])],
            'subscription_ends_at' => ['nullable', 'date'],
            'extend_months' => ['nullable', 'integer', 'min:1', 'max:36'],
        ]);

        if ($months = $data['extend_months'] ?? null) {
            $from = $tenant->subscription_ends_at?->isFuture() ? $tenant->subscription_ends_at : now();
            $data['subscription_ends_at'] = $from->addMonths((int) $months);
        }

        unset($data['extend_months']);
        $tenant->update($data);

        return back()->with('success', 'تم تحديث بيانات المشترك.');
    }

    public function toggleStatus(Tenant $tenant): RedirectResponse
    {
        $tenant->update([
            'status' => $tenant->isActive() ? Tenant::STATUS_SUSPENDED : Tenant::STATUS_ACTIVE,
        ]);

        return back()->with('success', $tenant->isActive() ? 'تم تفعيل الحساب.' : 'تم تعطيل الحساب.');
    }
}
