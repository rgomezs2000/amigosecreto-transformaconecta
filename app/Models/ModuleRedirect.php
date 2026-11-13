<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ruta anterior de un módulo, para redirigir enlaces ya compartidos cuando
 * su slug (o el de su categoría) cambia.
 */
class ModuleRedirect extends Model
{
    protected $fillable = [
        'path',
        'module_id',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
