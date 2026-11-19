<?php

namespace App\Models;

use App\Models\Concerns\HasAuditFields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuenta vinculada de Google (provider_user_id = `sub` del proveedor, no el correo).
 */
class SocialAccount extends Model
{
    use HasAuditFields;
    protected $fillable = [
        'user_id',
        'provider',
        'provider_user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

