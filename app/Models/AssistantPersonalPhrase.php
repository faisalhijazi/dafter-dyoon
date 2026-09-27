<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A phrase a merchant taught the assistant through «ماذا قصدت؟». Only affects that tenant.
 *
 * @property int $id
 * @property string $masked_text
 * @property string $intent
 * @property int $hits
 */
class AssistantPersonalPhrase extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'masked_text', 'intent', 'hits'];
}
