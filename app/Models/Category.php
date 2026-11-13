<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Category extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'sort_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * El slug de la categoría forma parte de la URL de sus módulos: si
     * cambia, todas esas rutas cambian con él. Se recuerdan las anteriores
     * para responder con un 301 en vez de un 404.
     */
    protected static function booted(): void
    {
        static::updating(function (Category $category) {
            if (! $category->isDirty('slug')) {
                return;
            }

            foreach ($category->modules()->get() as $module) {
                // La URL nueva se calcula con el slug en curso; la anterior,
                // con el que todavía está guardado en la base de datos.
                $module->setRelation('category', $category);

                $previous = $module->originalPath();

                if ($previous !== '') {
                    $module->rememberPath($previous);
                }
            }
        });
    }

    public function modules(): HasMany
    {
        return $this->hasMany(Module::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%' . $term . '%';

        return $query->where(function (Builder $query) use ($like) {
            $query->where('name', 'like', $like)
                ->orWhere('slug', 'like', $like);
        });
    }

    public static function generateSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'categoria';
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Una categoría no se elimina mientras tenga módulos asociados.
     */
    public function deletionBlocker(): ?string
    {
        if ($this->modules()->exists()) {
            return 'modules';
        }

        return null;
    }

    public function deleteCategory(): bool
    {
        if ($this->deletionBlocker() !== null) {
            return false;
        }

        return (bool) $this->delete();
    }
}
