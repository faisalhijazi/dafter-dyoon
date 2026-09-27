<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One chat bubble of «مساعد AI». Assistant messages carry `payload`:
 *   blocks  — structured cards rendered by the chat page
 *   pending — a write action awaiting the merchant's confirmation / choice
 *
 * @property array<string, mixed>|null $payload
 */
class AssistantMessage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'role', 'content', 'intent', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    /** @return array<string, mixed>|null */
    public function pending(): ?array
    {
        return $this->payload['pending'] ?? null;
    }
}
