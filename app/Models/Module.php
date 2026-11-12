<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'group',
        'category_id',
        'icon',
        'order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Cada módulo pertenece siempre a una categoría. Si se crea sin
     * categoría, se toma (o se crea) la que corresponde a su grupo.
     */
    protected static function booted(): void
    {
        static::creating(function (Module $module) {
            if ($module->category_id === null && filled($module->group)) {
                $categorySlug = in_array($module->group, ['General', 'Sesión'], true)
                    ? null
                    : Category::generateSlug($module->group);

                $category = Category::query()->firstOrCreate(
                    ['name' => $module->group],
                    [
                        'slug' => $categorySlug,
                        'sort_order' => (int) Category::query()->max('sort_order') + 1,
                        'is_active' => true,
                    ]
                );

                $module->category_id = $category->id;
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'module_permission');
    }

    /**
     * Accesos de perfiles/usuarios a este módulo.
     */
    public function profilePermissions(): HasMany
    {
        return $this->hasMany(ProfilePermissionModule::class);
    }

    /**
     * Ruta pública del módulo: /[categoria]/[modulo].
     * General y Sesión no llevan categoría; Inicio es la raíz.
     */
    public function url(): string
    {
        if ($this->slug === '') {
            return route('home');
        }

        if ($this->slug === 'logout') {
            return route('logout');
        }

        if ($this->slug === 'login') {
            return route('login');
        }

        // Módulos con página propia fuera de /[categoria]/[modulo].
        $explicitRoutes = [
            'control-notificaciones' => 'notificaciones.index',
            'moderacion' => 'moderacion.index',
            'restablecer-dinamica' => 'dinamica.index',
            'asignar-amigo' => 'sorteo.index',
            'ver-tu-amigo' => 'ver-tu-amigo.index',
        ];

        if (isset($explicitRoutes[$this->slug])) {
            return route($explicitRoutes[$this->slug]);
        }

        $categorySlug = $this->category?->slug;

        return $categorySlug
            ? url('/'.$categorySlug.'/'.$this->slug)
            : url('/'.$this->slug);
    }

    /**
     * "access"       → tiene accesos de perfiles/usuarios (matriz).
     * "permissions"  → tiene permisos asociados.
     */
    public function deletionBlocker(): ?string
    {
        if ($this->profilePermissions()->exists()) {
            return 'access';
        }

        if ($this->permissions()->exists()) {
            return 'permissions';
        }

        return null;
    }

    public function deleteModule(): bool
    {
        if ($this->deletionBlocker() !== null) {
            return false;
        }

        return (bool) $this->delete();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $query) use ($like) {
            $query->where('name', 'like', $like)
                ->orWhere('slug', 'like', $like);
        });
    }
}
