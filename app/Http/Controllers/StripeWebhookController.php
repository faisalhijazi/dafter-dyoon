<?php

namespace App\Http\Controllers;

use App\Services\StripeBilling;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeBilling $stripe): Response
    {
        try {
            $event = $stripe->verifyWebhook($request->getContent(), $request->header('Stripe-Signature'));
        } catch (RuntimeException|\JsonException) {
            return response('Invalid signature', 400);
        }

        $stripe->handle($event);

        return response('ok');
    }
}
