<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupSuspension extends Model
{
    protected $table = 'chat_group_suspensions';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'suspended_by',
        'until',
        'reason',
    ];

    protected function casts(): array
    {
        return ['until' => 'datetime'];
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
        return $this->until !== null && $this->until->isFuture();
    }
}
