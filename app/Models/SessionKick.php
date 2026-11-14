<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionKick extends Model
{
    protected $table = 'session_kicks';

    protected $fillable = [
        'session_id',
        'user_id',
        'message',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
