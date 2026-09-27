<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One sentence the assistant handled, privacy-masked ("سجل xnum علي xname بضاعه"),
 * with the signal it produced. Raw text and customer names are never stored here.
 *
 * @property int $id
 * @property string $masked_text
 * @property string|null $intent
 * @property string $source
 * @property string $status
 */
class AssistantSample extends Model
{
    use BelongsToTenant;

    public const SOURCES = ['answered', 'confirmed', 'corrected', 'unknown'];

    protected $fillable = ['tenant_id', 'user_id', 'assistant_message_id', 'masked_text', 'intent', 'source', 'status'];
}
