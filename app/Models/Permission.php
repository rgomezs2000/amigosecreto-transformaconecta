<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Permission extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'module_permission');
    }

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'profile_permission_module')
            ->withPivot('module_id')
            ->distinct();
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
                ->orWhere('slug', 'like', $like)
                ->orWhere('description', 'like', $like);
        });
    }

    public static function generateSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'permiso';
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * "modules" → disponible en módulos; "roles" → concedido a roles.
     */
    public function deletionBlocker(): ?string
    {
        if ($this->modules()->exists()) {
            return 'modules';
        }

        if ($this->profiles()->exists()) {
            return 'roles';
        }

        return null;
    }

    public function deletePermission(): bool
    {
        if ($this->deletionBlocker() !== null) {
            return false;
        }

        return (bool) $this->delete();
    }
}
