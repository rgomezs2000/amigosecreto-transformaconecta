<?php

namespace App\Models;

use App\Enums\ParticipantStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EventParticipant extends Model
{
    protected $fillable = [
        'event_id',
        'user_id',
        'status',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ParticipantStatus::class,
            'confirmed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Lista de deseos del participante en este evento (una sola). */
    public function wishlist(): HasOne
    {
        return $this->hasOne(Wishlist::class);
    }
}
