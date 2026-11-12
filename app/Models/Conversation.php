<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $table = 'chat_conversations';

    protected $fillable = [
        'name',
        'type',
        'created_by',
        'event_id',
        'assignment_id',
        'is_moderated',
    ];

    protected function casts(): array
    {
        return [
            'is_moderated' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_conversation_user')
            ->withPivot(['role', 'last_read_at', 'muted_at', 'cleared_at'])
            ->withCasts(['last_read_at' => 'datetime', 'muted_at' => 'datetime', 'cleared_at' => 'datetime'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isGroup(): bool
    {
        return $this->type === 'group';
    }

    public function titleFor(User $user): string
    {
        if ($this->type === 'secret') {
            return 'Amigo secreto';
        }

        if ($this->isGroup()) {
            return $this->name ?: 'Grupo';
        }

        $other = $this->users->firstWhere('id', '!=', $user->id);

        return $other?->name ?? ($this->name ?: 'Conversación');
    }
}
