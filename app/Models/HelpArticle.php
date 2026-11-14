<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contenido estático del centro de ayuda.
 *
 * Cada fila es un bloque de una página de Ayuda (Acerca de, Guía o FAQ).
 * El cuerpo se guarda como una lista de bloques con su tipo, para que las
 * vistas lo dibujen sin interpretar HTML: párrafos, listas, pasos, notas,
 * datos y versiones.
 */
class HelpArticle extends Model
{
    public const KIND_SECTION = 'section';

    public const KIND_FAQ = 'faq';

    /** Visible para cualquier persona con acceso al módulo. */
    public const AUDIENCE_ALL = 'all';

    /** Visible solo para cuentas con rol Administrador. */
    public const AUDIENCE_ADMIN = 'admin';

    protected $fillable = [
        'module_id',
        'slug',
        'title',
        'kind',
        'audience',
        'topic',
        'icon',
        'body',
        'sort_order',
        'is_published',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body' => 'array',
            'sort_order' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeForAudience(Builder $query, string $audience): Builder
    {
        return $query->where('audience', $audience);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
