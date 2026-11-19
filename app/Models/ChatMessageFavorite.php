<?php

namespace App\Models;

use App\Models\Concerns\HasAuditFields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessageFavorite extends Model
{
    use HasAuditFields;
    protected $table = 'chat_message_favorites';

    protected $fillable = [
        'message_id',
        'user_id',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

