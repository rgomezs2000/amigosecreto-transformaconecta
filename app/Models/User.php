<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
        'profile_id',
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
            'last_seen_at' => 'datetime',
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
     * ¿La cuenta está bloqueada?
     *
     * Cualquier locked_until no nulo es un bloqueo permanente hasta que un
     * administrador lo limpie.
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null;
    }

    /**
     * Suma un intento fallido y bloquea al llegar al máximo.
     *
     * El bloqueo es permanente: solo un administrador puede levantarlo con
     * `php artisan user:unlock {usuario}`.
     */
    public function registerFailedLogin(int $maxAttempts = 3): void
    {
        $this->failed_login_attempts = (int) $this->failed_login_attempts + 1;

        if ($this->failed_login_attempts >= $maxAttempts) {
            $this->locked_until = now();
        }

        $this->save();
    }

    /**
     * Limpia el contador tras un acceso correcto.
     *
     * No borra locked_until: el bloqueo es permanente hasta que un
     * administrador lo limpie.
     */
    public function resetLoginAttempts(): void
    {
        $this->forceFill([
            'failed_login_attempts' => 0,
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

    /**
     * Scope: ¿el login (usuario) ya está ocupado por otra cuenta?
     */
    public function scopeLoginExists(Builder $query, string $login, ?int $ignoreId = null): Builder
    {
        $login = mb_strtolower(trim($login));

        return $query
            ->whereRaw('LOWER(username) = ?', [$login])
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId));
    }

    /**
     * ¿El login (usuario) ya está ocupado por otra cuenta?
     */
    public function loginExists(string $login, ?int $ignoreId = null): bool
    {
        $login = mb_strtolower(trim($login));

        if ($login === '') {
            return false;
        }

        return static::query()->loginExists($login, $ignoreId)->exists();
    }

    /**
     * Actualiza los datos editables del perfil propio.
     *
     * @param  array{name:string,email:string,username:string}  $data
     */
    public function updateProfile(array $data): bool
    {
        return $this->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'username' => $data['username'],
        ])->save();
    }

    /**
     * Cambia la clave. El cast "hashed" la encripta al guardar.
     */
    public function updatePassword(string $password): bool
    {
        return $this->forceFill([
            'password' => $password,
        ])->save();
    }

    /**
     * Envía el correo de bienvenida al crear la cuenta.
     *
     * Reutiliza el helper de correos y la plantilla accesible del sistema.
     */
    public function sendWelcomeEmail(): bool
    {
        $appName = config('app.name', 'Amigo Secreto Inclusivo');

        return send_mail(
            $this->email,
            'Tu cuenta está lista · '.$appName,
            'Tu cuenta está lista',
            [
                'Hola, '.$this->name.'.',
                'Tu cuenta en '.$appName.' fue creada correctamente.',
                'Ya puedes entrar con tu usuario o correo electrónico.',
            ],
            [
                'label' => 'Entrar al sistema',
                'url' => route('login'),
            ],
            [
                'recipient_name' => $this->name,
                'eyebrow' => 'Cuenta creada',
                'preheader' => 'Tu cuenta ya está disponible.',
            ]
        );
    }

    /**
     * Envía al usuario sus datos de acceso: nombre, usuario, correo, rol y clave.
     */
    public function sendCredentialsEmail(string $plainPassword, ?Profile $profile = null): bool
    {
        $profile ??= $this->profile;
        $roleName = $profile?->name ?? $this->role->label();
        $appName = config('app.name', 'Tu Amigo Secreto');

        return send_mail(
            $this->email,
            'Tus datos de acceso · '.$appName,
            'Tu cuenta está lista',
            [
                'Hola, '.$this->name.'.',
                'Tu cuenta en '.$appName.' fue creada correctamente.',
                'Nombre: '.$this->name,
                'Usuario: '.$this->username,
                'Correo: '.$this->email,
                'Rol: '.$roleName,
                'Clave: '.$plainPassword,
                'Puedes cambiar la clave cuando quieras desde tu perfil.',
            ],
            [
                'label' => 'Entrar al sistema',
                'url' => route('login'),
            ],
            [
                'recipient_name' => $this->name,
                'eyebrow' => 'Cuenta creada',
                'preheader' => 'Tus datos de acceso a '.$appName.'.',
            ]
        );
    }

    /**
     * Envía la nueva clave al restablecerla.
     */
    public function sendPasswordResetEmail(string $plainPassword): bool
    {
        $appName = config('app.name', 'Tu Amigo Secreto');

        return send_mail(
            $this->email,
            'Tu contraseña fue restablecida · '.$appName,
            'Tu contraseña fue restablecida',
            [
                'Hola, '.$this->name.'.',
                'El administrador restableció tu contraseña.',
                'Usuario: '.$this->username,
                'Nueva clave: '.$plainPassword,
                'Al entrar, puedes cambiarla desde tu perfil.',
            ],
            [
                'label' => 'Entrar al sistema',
                'url' => route('login'),
            ],
            [
                'recipient_name' => $this->name,
                'eyebrow' => 'Contraseña restablecida',
                'preheader' => 'Tu nueva clave de acceso.',
            ]
        );
    }

    /**
     * Búsqueda por nombre, correo o usuario.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $query) use ($like) {
            $query->where('name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('username', 'like', $like);
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Notificaciones del usuario con los campos de entrega del sistema
     * (canal, sonido, aviso visual, enlace y origen).
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(Notification::class, 'notifiable')->latest();
    }

    /**
     * Preferencias individuales de notificación (sonido, push, avisos).
     */
    public function notificationPreference(): HasOne
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    /**
     * Módulos a los que puede entrar: los del perfil, más los concedidos a
     * mano, menos los revocados a mano.
     *
     * @return Collection<int, Module>
     */
    public function accessibleModules(): Collection
    {
        $profileGrants = $this->profile_id
            ? ProfilePermissionModule::query()
                ->where('profile_id', $this->profile_id)
                ->pluck('module_id')
            : collect();

        $userGrants = UserPermissionModule::query()
            ->where('user_id', $this->id)
            ->where('allowed', true)
            ->pluck('module_id');

        $userDenials = UserPermissionModule::query()
            ->where('user_id', $this->id)
            ->where('allowed', false)
            ->pluck('module_id');

        $moduleIds = $profileGrants
            ->merge($userGrants)
            ->unique()
            ->diff($userDenials)
            ->values();

        return Module::query()
            ->whereIn('id', $moduleIds)
            ->where('is_active', true)
            ->orderBy('order')
            ->get();
    }

    public function hasModuleAccess(Module $module): bool
    {
        return $this->accessibleModules()->contains('id', $module->id);
    }

    /**
     * Permisos efectivos del usuario sobre un módulo.
     *
     * @return Collection<int, Permission>
     */
    public function permissionsForModule(Module $module): Collection
    {
        $profileGrants = $this->profile_id
            ? ProfilePermissionModule::query()
                ->where('profile_id', $this->profile_id)
                ->where('module_id', $module->id)
                ->pluck('permission_id')
            : collect();

        $userGrants = UserPermissionModule::query()
            ->where('user_id', $this->id)
            ->where('module_id', $module->id)
            ->where('allowed', true)
            ->pluck('permission_id');

        $userDenials = UserPermissionModule::query()
            ->where('user_id', $this->id)
            ->where('module_id', $module->id)
            ->where('allowed', false)
            ->pluck('permission_id');

        return Permission::query()
            ->whereIn('id', $profileGrants->merge($userGrants)->unique()->diff($userDenials))
            ->get();
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

    /**
     * Usuarios que este usuario tiene bloqueados en el chat.
     */
    public function blockedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_blocks', 'blocker_id', 'blocked_id')
            ->withTimestamps();
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
