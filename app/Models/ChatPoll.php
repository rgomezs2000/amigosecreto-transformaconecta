<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatPoll extends Model
{
    protected $table = 'chat_polls';

    protected $fillable = [
        'message_id',
        'conversation_id',
        'created_by',
        'question',
        'multiple',
        'hide_voters',
        'ends_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'multiple' => 'boolean',
            'hide_voters' => 'boolean',
            'ends_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ChatPollOption::class, 'poll_id')->orderBy('position')->orderBy('id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ChatPollVote::class, 'poll_id');
    }

    /**
     * Una encuesta cerrada ya no admite votos: o se cerró a mano o pasó su
     * fecha de finalización (24 horas por defecto).
     */
    public function isClosed(): bool
    {
        return $this->closed_at !== null
            || ($this->ends_at !== null && $this->ends_at->isPast());
    }

    public function close(): void
    {
        if ($this->closed_at === null) {
            $this->forceFill(['closed_at' => now()])->save();
        }
    }
}
