<?php

namespace App\Models;

use App\Enums\ParticipantStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EventParticipant extends Model
{
    protected $fillable = [
        'event_id',
        'user_id',
        'status',
        'confirmed_at',
        'notes',
        'invitation_token',
        'invited_at',
        'responded_at',
        'status_reason',
        'status_changed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ParticipantStatus::class,
            'confirmed_at' => 'datetime',
            'invited_at' => 'datetime',
            'responded_at' => 'datetime',
            'status_changed_at' => 'datetime',
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

    /** Lista de deseos por elementos (varios por participante). */
    public function wishItems(): HasMany
    {
        return $this->hasMany(WishItem::class, 'user_id', 'user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ParticipantStatus::Active->value);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ParticipantStatus::Pending->value);
    }

    /** Enlace personalizado de la invitación. */
    public function invitationUrl(): ?string
    {
        if (blank($this->invitation_token)) {
            return null;
        }

        return route('invitacion.abrir', ['token' => $this->invitation_token]);
    }
}
