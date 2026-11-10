<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pista anónima que envía quien regala a su amigo secreto.
 *
 * El receptor nunca ve `assignment.giver_id`; la consulta se hace siempre por
 * `assignment.receiver_id = yo`.
 */
class Clue extends Model
{
    protected $fillable = [
        'assignment_id',
        'template_key',
        'body',
        'sent_at',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }
}
