<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatPollOption extends Model
{
    protected $table = 'chat_poll_options';

    protected $fillable = [
        'poll_id',
        'label',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(ChatPoll::class, 'poll_id');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ChatPollVote::class, 'option_id');
    }
}
