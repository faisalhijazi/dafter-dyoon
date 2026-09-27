<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlanController extends Controller
{
    public function index(): View
    {
        return view('admin.plans.index', [
            'plans' => Plan::withCount('tenants')->orderBy('sort_order')->get(),
        ]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'price_monthly' => ['required', 'numeric', 'min:0'],
            'price_yearly' => ['required', 'numeric', 'min:0'],
            'max_accounts' => ['nullable', 'integer', 'min:1'],
            'max_transactions' => ['nullable', 'integer', 'min:1'],
            'max_staff' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'features_text' => ['nullable', 'string', 'max:2000'],
            'free_currencies_text' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z]{3}(\s*,\s*[A-Za-z]{3})*$/'],
        ]);

        $plan->update([
            ...collect($data)->except(['features_text', 'free_currencies_text'])->all(),
            'free_currencies' => collect(explode(',', (string) ($data['free_currencies_text'] ?? '')))
                ->map(fn ($code) => strtoupper(trim($code)))->filter()->unique()->values()->all(),
            'multi_currency' => $request->boolean('multi_currency'),
            'debt_limits' => $request->boolean('debt_limits'),
            'cloud_backup' => $request->boolean('cloud_backup'),
            'live_statement' => $request->boolean('live_statement'),
            'is_active' => $request->boolean('is_active'),
            'features' => collect(preg_split('/\r?\n/', (string) ($data['features_text'] ?? '')))
                ->map(fn ($l) => trim($l))->filter()->values()->all(),
        ]);

        return back()->with('success', 'تم حفظ الخطة '.$plan->name.'.');
    }
}
