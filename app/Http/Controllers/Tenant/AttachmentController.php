<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves transaction photos from private storage; the tenant scope on the
 * route binding guarantees a tenant can only open its own attachments.
 */
class AttachmentController extends Controller
{
    public function __invoke(Transaction $transaction): StreamedResponse
    {
        abort_unless($transaction->attachment_path && Storage::disk('local')->exists($transaction->attachment_path), 404);

        return Storage::disk('local')->response($transaction->attachment_path);
    }
}
