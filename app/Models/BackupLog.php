<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BackupLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'user_id', 'provider', 'action', 'file_name',
        'remote_id', 'size_bytes', 'status', 'message',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
