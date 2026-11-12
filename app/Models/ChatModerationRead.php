<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hasta dónde leyó un administrador una conversación que solo modera.
 *
 * No se usa la tabla pivote del chat porque el administrador no participa en
 * la conversación: solo la supervisa.
 */
class ChatModerationRead extends Model
{
    protected $table = 'chat_moderation_reads';

    protected $fillable = ['user_id', 'conversation_id', 'last_read_at'];

    protected function casts(): array
    {
        return ['last_read_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
