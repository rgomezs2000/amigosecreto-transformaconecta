<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Parámetros generales de la aplicación que se guardan en la base de datos.
 *
 * Se usan para datos estables que la interfaz necesita mostrar (por ejemplo,
 * la versión del sistema) sin repetirlos en el código de las vistas.
 */
class AppSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    /**
     * Lee un parámetro; devuelve el valor de respaldo si no existe.
     */
    public static function getValue(string $key, ?string $default = null): ?string
    {
        $value = static::query()->where('key', $key)->value('value');

        return $value !== null ? (string) $value : $default;
    }

    /**
     * Crea o actualiza un parámetro.
     */
    public static function putValue(string $key, ?string $value): self
    {
        return static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}
