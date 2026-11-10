<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Valores por defecto en memoria (accesibilidad y avisos apagados por
     * defecto, que es lo seguro para el perfil TEA).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'participant',
        'is_active' => true,
        'must_change_password' => false,
        'theme' => 'calm',
        'font_scale' => 100,
        'reduce_motion' => true,
        'sound_enabled' => false,
        'notify_email' => true,
        'notify_whatsapp' => false,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'role',
        'is_active',
        'must_change_password',
        'phone',
        'notify_email',
        'notify_whatsapp',
        'theme',
        'font_scale',
        'reduce_motion',
        'sound_enabled',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'notify_email' => 'boolean',
            'notify_whatsapp' => 'boolean',
            'font_scale' => 'integer',
            'reduce_motion' => 'boolean',
            'sound_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function hasPassword(): bool
    {
        return filled($this->password);
    }

    /**
     * ¿La cuenta está bloqueada por intentos fallidos?
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Suma un intento fallido y bloquea al llegar al máximo.
     */
    public function registerFailedLogin(int $maxAttempts = 3, int $lockoutMinutes = 15): void
    {
        // Si el bloqueo anterior ya venció, se empieza de cero.
        if ($this->locked_until !== null && $this->locked_until->isPast()) {
            $this->failed_login_attempts = 0;
            $this->locked_until = null;
        }

        $this->failed_login_attempts = (int) $this->failed_login_attempts + 1;

        if ($this->failed_login_attempts >= $maxAttempts) {
            $this->locked_until = now()->addMinutes($lockoutMinutes);
        }

        $this->save();
    }

    /**
     * Limpia intentos y bloqueo tras un acceso correcto.
     */
    public function resetLoginAttempts(): void
    {
        $this->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
        ])->save();
    }

    /**
     * Busca por usuario o correo, sin distinguir mayúsculas.
     */
    public function scopeFindByLogin(Builder $query, string $login): Builder
    {
        $login = mb_strtolower(trim($login));

        return $query->where(function (Builder $query) use ($login) {
            $query->whereRaw('LOWER(username) = ?', [$login])
                ->orWhereRaw('LOWER(email) = ?', [$login]);
        });
    }

    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'event_participants')
            ->withPivot(['status', 'confirmed_at'])
            ->withTimestamps();
    }

    public function participations(): HasMany
    {
        return $this->hasMany(EventParticipant::class);
    }

    public function givenAssignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'giver_id');
    }

    public function receivedAssignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'receiver_id');
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where('role', UserRole::Admin->value);
    }
}
