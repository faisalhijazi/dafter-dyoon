<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\StripeBilling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Paying for a plan with Stripe (owner only): Checkout to subscribe, the Customer Portal to manage it.
 */
class BillingController extends Controller
{
    public function checkout(Request $request, StripeBilling $stripe): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('is_active', true)],
            'interval' => ['required', Rule::in(array_keys(StripeBilling::INTERVALS))],
        ]);

        $tenant = $request->user()->tenant;

        try {
            // Already subscribed: change or cancel from the portal instead of starting a second subscription.
            if ($tenant->hasStripeSubscription()) {
                return redirect()->away($stripe->portal($tenant));
            }

            return redirect()->away($stripe->checkout($tenant, Plan::findOrFail($data['plan_id']), $data['interval'], $request->user()));
        } catch (RuntimeException $e) {
            return redirect()->route('tenant.upgrade')->with('error', $e->getMessage());
        }
    }

    public function success(Request $request, StripeBilling $stripe): RedirectResponse
    {
        $sessionId = (string) $request->query('session_id');
        $tenant = $request->user()->tenant;

        try {
            $active = $sessionId !== '' && $stripe->completeCheckout($tenant, $sessionId);
        } catch (RuntimeException) {
            $active = false;
        }

        return redirect()->route('tenant.upgrade')->with('success', $active
            ? 'تم الاشتراك بنجاح 🎉 تم تفعيل خطة '.$tenant->plan->name.'.'
            : 'تم استلام الدفع، وسيتم تفعيل خطتك خلال دقائق.');
    }

    public function portal(Request $request, StripeBilling $stripe): RedirectResponse
    {
        try {
            return redirect()->away($stripe->portal($request->user()->tenant));
        } catch (RuntimeException $e) {
            return redirect()->route('tenant.upgrade')->with('error', $e->getMessage());
        }
    }
}
