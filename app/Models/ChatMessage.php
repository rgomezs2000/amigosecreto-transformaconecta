<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatMessage extends Model
{
    protected $table = 'chat_messages';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'body',
        'is_moderation',
        'link_url',
        'link_title',
        'link_description',
        'link_image',
        'latitude',
        'longitude',
        'delivered_at',
        'type',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'attachment_size',
        'edited_at',
        'deleted_at',
        'deleted_by',
        'deleted_by_admin',
    ];

    protected function casts(): array
    {
        return [
            'is_moderation' => 'boolean',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
            'delivered_at' => 'datetime',
            'deleted_by_admin' => 'boolean',
            'attachment_size' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ChatReaction::class, 'message_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatMessageAttachment::class, 'message_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ChatReport::class, 'message_id');
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }
}
