<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\AccessService;

class Profile extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'profile_permission_module')
            ->withPivot('module_id')
            ->distinct();
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'profile_permission_module')
            ->withPivot('permission_id')
            ->distinct();
    }

    /**
     * Filas de permisos/módulos asociadas al rol.
     */
    public function permissionModules(): HasMany
    {
        return $this->hasMany(ProfilePermissionModule::class);
    }

    /**
     * Búsqueda dinámica por nombre, slug o descripción.
     */
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

    /**
     * Slug único para un rol nuevo.
     */
    public static function generateSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'rol';
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Elimina el rol solo si no tiene usuarios.
     *
     * Las concesiones de permisos/módulos se eliminan en cascada.
     */
    /**
     * Motivo por el que el rol no se puede eliminar.
     *
     * "users"   → tiene usuarios asignados.
     * "access"  → tiene módulos/permisos concedidos (revocar en la matriz).
     */
    public function deletionBlocker(): ?string
    {
        if ($this->users()->exists()) {
            return 'users';
        }

        if ($this->permissionModules()->exists()) {
            return 'access';
        }

        return null;
    }

    /**
     * Solo elimina roles sin usuarios y sin módulos/permisos asociados.
     */
    public function deleteRole(): bool
    {
        if ($this->deletionBlocker() !== null) {
            return false;
        }

        return (bool) $this->delete();
    }

    /**
     * Aplica el estado completo de la matriz para este rol.
     *
     * @param  array<int, array{module_id:int, permission_ids:array<int, int>}>  $modulesState
     * @return array{granted:int, revoked:int}
     */
    public function applyMatrix(array $modulesState): array
    {
        $access = app(AccessService::class);

        return DB::transaction(function () use ($access, $modulesState) {
            $granted = 0;
            $revoked = 0;

            foreach ($modulesState as $state) {
                $module = Module::query()
                    ->whereKey($state['module_id'] ?? null)
                    ->where('is_active', true)
                    ->first();

                if ($module === null) {
                    continue;
                }

                $desired = collect($state['permission_ids'] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->unique();

                $current = ProfilePermissionModule::query()
                    ->where('profile_id', $this->id)
                    ->where('module_id', $module->id)
                    ->pluck('permission_id');

                $toRevoke = Permission::query()
                    ->whereIn('id', $current->diff($desired))
                    ->get();

                foreach ($toRevoke as $permission) {
                    if ($access->revoke($this, $module, $permission)) {
                        $revoked++;
                    }
                }

                $toGrant = Permission::query()
                    ->whereIn('id', $desired->diff($current))
                    ->get();

                foreach ($toGrant as $permission) {
                    if ($access->grant($this, $module, $permission)) {
                        $granted++;
                    }
                }
            }

            return ['granted' => $granted, 'revoked' => $revoked];
        });
    }
}
