<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Assignment extends Model
{
    protected $fillable = [
        'event_id',
        'giver_id',
        'receiver_id',
        'drawn_at',
        'revealed_at',
        'reveal_note',
    ];

    protected function casts(): array
    {
        return [
            'drawn_at' => 'datetime',
            'revealed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** Quien regala (el dueño de la sesión cuando consulta "mi amigo"). */
    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'giver_id');
    }

    /** El amigo secreto. Nunca se expone a terceros. */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function exchangeGift(): HasOne
    {
        return $this->hasOne(ExchangeGift::class);
    }

    public function consumableGifts(): HasMany
    {
        return $this->hasMany(ConsumableGift::class);
    }

    /** Pistas que envía quien regala; el receptor las lee sin saber el autor. */
    public function clues(): HasMany
    {
        return $this->hasMany(Clue::class);
    }

    public function isRevealed(): bool
    {
        return $this->revealed_at !== null;
    }
}
