<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupBan extends Model
{
    protected $table = 'chat_group_bans';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'banned_by',
        'reason',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
