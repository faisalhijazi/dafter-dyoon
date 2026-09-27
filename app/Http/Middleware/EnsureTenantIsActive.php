<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only users attached to an active tenant may reach the tenant dashboard.
 */
class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->user()?->tenant;

        if (! $tenant) {
            abort(403, 'هذا المستخدم غير مرتبط بأي منشأة.');
        }

        if (! $tenant->isActive()) {
            return response()->view('tenant.suspended', ['tenant' => $tenant], 403);
        }

        // A staff member the owner switched off.
        if ($request->user()->is_active === false) {
            auth('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'تم إيقاف حسابك من قبل صاحب المتجر.']);
        }

        return $next($request);
    }
}
