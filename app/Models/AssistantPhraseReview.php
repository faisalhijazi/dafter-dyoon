<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The platform admin's decision on a masked phrase: approved phrases enter the shared
 * model immediately, rejected ones never do.
 *
 * @property string $masked_text
 * @property string|null $intent
 * @property string $decision
 */
class AssistantPhraseReview extends Model
{
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = ['masked_text', 'intent', 'decision', 'admin_id'];
}
