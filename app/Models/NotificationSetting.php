<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    protected $fillable = [
        'email_enabled',
        'push_enabled',
        'sound_enabled',
        'in_app_enabled',
    ];

    protected function casts(): array
    {
        return [
            'email_enabled' => 'boolean',
            'push_enabled' => 'boolean',
            'sound_enabled' => 'boolean',
            'in_app_enabled' => 'boolean',
        ];
    }

    /**
     * Configuración única del sistema.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'email_enabled' => true,
            'push_enabled' => true,
            'sound_enabled' => false,
            'in_app_enabled' => true,
        ]);
    }
}
