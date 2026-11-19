<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Rellena created_by, updated_by y deleted_by cuando la tabla los tiene.
 *
 * El borrado físico se convierte en lógico en los modelos que además usan
 * SoftDeletes; aquí solo se conserva la trazabilidad del usuario.
 */
trait HasAuditFields
{
    /** @var array<string, array<string, bool>> */
    private static array $auditColumns = [];

    private static function auditHasColumn(string $table, string $column): bool
    {
        if (! isset(self::$auditColumns[$table])) {
            self::$auditColumns[$table] = array_fill_keys(Schema::getColumnListing($table), true);
        }

        return isset(self::$auditColumns[$table][$column]);
    }

    protected static function bootHasAuditFields(): void
    {
        static::creating(function ($model) {
            if (Auth::check() && self::auditHasColumn($model->getTable(), 'created_by')) {
                $model->created_by = $model->created_by ?: Auth::id();
            }
        });

        static::updating(function ($model) {
            if (Auth::check() && self::auditHasColumn($model->getTable(), 'updated_by')) {
                $model->updated_by = Auth::id();
            }
        });

        static::deleting(function ($model) {
            if (! Auth::check() || ! self::auditHasColumn($model->getTable(), 'deleted_by')) {
                return;
            }

            $model->deleted_by = $model->deleted_by ?: Auth::id();

            // En SoftDeletes el UPDATE de deleted_at se hace después; se
            // persiste deleted_by primero para que quede en la misma fila.
            try {
                $model->saveQuietly();
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
