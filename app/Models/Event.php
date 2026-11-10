<?php

namespace App\Models;

use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Event extends Model
{
    /**
     * Valores por defecto en memoria, para que un evento recién creado ya
     * tenga moneda y banderas aunque la base las complete.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'currency' => 'USD',
        'reveal_shows_value' => false,
        'allow_mutual_pairs' => false,
    ];

    protected $fillable = [
        'name',
        'year',
        'status',
        'registration_opens_at',
        'registration_closes_at',
        'dynamic_starts_at',
        'exchange_at',
        'min_value',
        'max_value',
        'consumable_value_cap',
        'reveal_shows_value',
        'allow_mutual_pairs',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'status' => EventStatus::class,
            'registration_opens_at' => 'datetime',
            'registration_closes_at' => 'datetime',
            'dynamic_starts_at' => 'datetime',
            'exchange_at' => 'datetime',
            'min_value' => 'decimal:2',
            'max_value' => 'decimal:2',
            'consumable_value_cap' => 'decimal:2',
            'reveal_shows_value' => 'boolean',
            'allow_mutual_pairs' => 'boolean',
        ];
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_participants')
            ->withPivot(['status', 'confirmed_at'])
            ->withTimestamps();
    }

    public function participations(): HasMany
    {
        return $this->hasMany(EventParticipant::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    public function exchangeGifts(): HasManyThrough
    {
        return $this->hasManyThrough(ExchangeGift::class, Assignment::class);
    }

    public function consumableGifts(): HasManyThrough
    {
        return $this->hasManyThrough(ConsumableGift::class, Assignment::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            EventStatus::Registration->value,
            EventStatus::DrawEnabled->value,
            EventStatus::Drawn->value,
        ]);
    }

    public function isDrawEnabled(): bool
    {
        return $this->status === EventStatus::DrawEnabled;
    }

    public function hasAssignments(): bool
    {
        return $this->assignments()->exists();
    }
}
