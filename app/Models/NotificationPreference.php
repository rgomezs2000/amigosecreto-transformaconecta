<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preferencias individuales de notificación.
 *
 * Cada campo es anulable: `null` significa "heredar la configuración global"
 * de NotificationSetting. Así el administrador decide el comportamiento
 * general y cada persona puede silenciar el sonido sin perder los avisos
 * visuales dentro de la aplicación.
 */
class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'push_enabled',
        'sound_enabled',
        'chat_sound_enabled',
        'in_app_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'push_enabled' => 'boolean',
            'sound_enabled' => 'boolean',
            'chat_sound_enabled' => 'boolean',
            'in_app_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Preferencias del usuario (objeto en memoria si aún no existen).
     */
    public static function forUser(User $user): self
    {
        return static::query()->firstOrNew(['user_id' => $user->id]);
    }

    /**
     * Valores efectivos: lo individual si está definido, si no lo global.
     *
     * @return array{push:bool,sound:bool,chat_sound:bool,in_app:bool}
     */
    public function resolved(?NotificationSetting $settings = null): array
    {
        $settings ??= NotificationSetting::current();

        return [
            'push' => $this->push_enabled ?? (bool) $settings->push_enabled,
            'sound' => $this->sound_enabled ?? (bool) $settings->sound_enabled,
            'chat_sound' => $this->chat_sound_enabled
                ?? $this->sound_enabled
                ?? (bool) $settings->sound_enabled,
            'in_app' => $this->in_app_enabled ?? (bool) $settings->in_app_enabled,
        ];
    }
}
