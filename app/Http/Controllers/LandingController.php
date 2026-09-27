<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\View\View;

class LandingController extends Controller
{
    public function __invoke(): View
    {
        return view('landing', [
            'plans' => Plan::where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }
}
