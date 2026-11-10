<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lista de deseos del participante para un evento.
 *
 * `avoid` contiene restricciones sensoriales y alérgenos: es un dato sensible.
 * Solo lo puede ver quien le regala y el administrador.
 */
class Wishlist extends Model
{
    protected $fillable = [
        'event_participant_id',
        'likes',
        'avoid',
        'categories',
    ];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
        ];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(EventParticipant::class, 'event_participant_id');
    }
}
