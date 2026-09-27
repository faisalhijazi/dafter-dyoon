<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\GoogleDriveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class GoogleDriveController extends Controller
{
    public function __construct(private GoogleDriveService $drive) {}

    public function redirect(Request $request): RedirectResponse
    {
        $tenant = $request->user()->tenant;

        if (! $tenant->canUse('cloud_backup')) {
            return redirect()->route('tenant.upgrade');
        }

        if (! $this->drive->isConfigured()) {
            return redirect()->route('tenant.backup')->with('error', 'لم يتم إعداد مفاتيح Google على المنصة بعد (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET).');
        }

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away($this->drive->authorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull('google_oauth_state');

        if (! $expected || ! hash_equals($expected, (string) $request->query('state')) || ! $request->filled('code')) {
            return redirect()->route('tenant.backup')->with('error', 'تم إلغاء الربط أو انتهت صلاحية الطلب.');
        }

        try {
            $this->drive->connect($request->user()->tenant, $request->query('code'));
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('tenant.backup')->with('error', 'تعذّر ربط حساب Google، حاول مرة أخرى.');
        }

        return redirect()->route('tenant.backup')->with('success', 'تم ربط حساب Google Drive بنجاح.');
    }
}
