<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Module extends Model
{
    protected $fillable = [
        'code',
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
            // Las migraciones más antiguas crean módulos antes de que exista
            // la columna code; en ese caso el código se rellena después.
            if (blank($module->code) && Schema::hasColumn('modules', 'code')) {
                $module->code = static::codeFor((string) $module->slug);
            }

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

        // La URL del módulo sale de su categoría y de su slug. Cuando cambia
        // alguno de los dos, la ruta anterior se recuerda para redirigir en
        // lugar de romper los enlaces que ya se compartieron.
        static::updating(function (Module $module) {
            if (! $module->isDirty('slug') && ! $module->isDirty('category_id')) {
                return;
            }

            $previous = $module->originalPath();

            if ($previous === '' || $previous === $module->path()) {
                return;
            }

            $module->rememberPath($previous);
        });

        static::deleted(function (Module $module) {
            $module->redirects()->delete();
        });
    }

    /**
     * Código interno estable a partir del slug inicial del módulo.
     *
     * Se genera una sola vez (al crear el módulo) y no cambia aunque después
     * se renombre el slug o el nombre.
     */
    public static function codeFor(string $slug): string
    {
        $code = trim($slug) !== '' ? trim($slug) : 'inicio';

        if (! static::query()->where('code', $code)->exists()) {
            return $code;
        }

        do {
            $code = Str::slug($slug !== '' ? $slug : 'inicio').'-'.Str::lower(Str::random(5));
        } while (static::query()->where('code', $code)->exists());

        return $code;
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
     * Rutas anteriores del módulo (para redirigir enlaces antiguos).
     */
    public function redirects(): HasMany
    {
        return $this->hasMany(ModuleRedirect::class);
    }

    /**
     * Ruta pública del módulo, sin barra inicial: «perfil»,
     * «maestros/roles», «amigo-secreto/asignar-amigo»... Vacía para Inicio.
     */
    public function path(): string
    {
        if (blank($this->slug)) {
            return '';
        }

        $categorySlug = $this->category?->slug;

        return trim(($categorySlug ? $categorySlug.'/' : '').$this->slug, '/');
    }

    /**
     * URL pública del módulo: /[categoria]/[modulo].
     *
     * Inicio, Login y Salir conservan sus rutas propias del sistema.
     */
    public function url(): string
    {
        if (blank($this->slug)) {
            return route('home');
        }

        return match ($this->code) {
            'login' => route('login'),
            'logout' => route('logout'),
            default => url('/'.$this->path()),
        };
    }

    /**
     * Busca el módulo cuya URL pública es exactamente la ruta indicada.
     *
     * La ruta se compone de la categoría (cuando tiene slug) y del módulo:
     * «perfil» o «amigo-secreto/ver-tu-amigo».
     *
     * @param  string|array<int, string>  $path
     */
    public static function findByPath(string|array $path): ?self
    {
        $segments = is_array($path) ? $path : explode('/', $path);
        $segments = array_values(array_filter($segments, fn (string $segment) => $segment !== ''));

        if (count($segments) === 1) {
            return static::query()
                ->where('is_active', true)
                ->where('slug', $segments[0])
                ->where(function (Builder $query) {
                    $query->whereNull('category_id')
                        ->orWhereHas('category', fn (Builder $category) => $category->whereNull('slug'));
                })
                ->first();
        }

        if (count($segments) === 2) {
            return static::query()
                ->where('is_active', true)
                ->where('slug', $segments[1])
                ->whereHas('category', fn (Builder $category) => $category->where('slug', $segments[0]))
                ->first();
        }

        return null;
    }

    /**
     * Códigos internos que antes fueron otro slug (bases de datos antiguas).
     *
     * @var array<string, array<int, string>>
     */
    private const LEGACY_CODES = [
        'perfil' => ['perfiles'],
        'roles' => ['maestros.roles'],
        'permisos' => ['maestros.permisos'],
        'modulos' => ['maestros.modulos'],
        'matriz' => ['maestros.matriz'],
        'categorias' => ['maestros.categorias'],
        'asignar-amigo' => ['amigos.asignar'],
        'ver-tu-amigo' => ['amigos.ver'],
        'lista-de-deseos' => ['amigos.deseos'],
        'intercambio-de-pista' => ['amigos.intercambio'],
        'gran-revelacion' => ['amigos.revelacion'],
        'obsequios' => ['amigos.obsequios'],
        'notificaciones' => ['amigos.notificaciones'],
        'participantes' => ['amigo.participantes'],
        'usuarios-asociados' => ['amigo.asociado'],
        'usuarios-obsequiados' => ['amigo.obsequiados'],
        'logout' => ['salir'],
    ];

    /**
     * Código canónico de un código o slug histórico.
     *
     * Las bases de datos antiguas guardaban slugs como «perfiles» o
     * «maestros.roles»; aquí se traducen al código actual para que sigan
     * apuntando al mismo módulo.
     */
    public static function canonicalCode(?string $candidate): ?string
    {
        if (blank($candidate)) {
            return null;
        }

        if (isset(self::LEGACY_CODES[$candidate])) {
            return $candidate;
        }

        foreach (self::LEGACY_CODES as $canonical => $legacy) {
            if (in_array($candidate, $legacy, true)) {
                return $canonical;
            }
        }

        return $candidate;
    }

    /**
     * Módulo activo por su código interno (independiente del slug actual).
     */
    public static function activeByCode(string $code): ?self
    {
        $module = static::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();

        if ($module !== null) {
            return $module;
        }

        // Compatibilidad con instalaciones cuyos módulos aún conservan el
        // slug histórico como código.
        $candidates = array_merge([$code], self::LEGACY_CODES[$code] ?? []);

        return static::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($candidates) {
                $query->whereIn('code', $candidates)->orWhereIn('slug', $candidates);
            })
            ->first();
    }

    /**
     * Guarda una ruta anterior para responder con un 301 hacia la actual.
     */
    public function rememberPath(string $path): void
    {
        $path = trim($path, '/');

        if ($path === '' || $path === $this->path() || ! Schema::hasTable('module_redirects')) {
            return;
        }

        ModuleRedirect::query()->updateOrCreate(
            ['path' => $path],
            ['module_id' => $this->id],
        );
    }

    /**
     * Ruta pública que tenía el módulo antes del cambio en curso.
     */
    public function originalPath(): string
    {
        $slug = (string) $this->getOriginal('slug');

        if ($slug === '') {
            return '';
        }

        $categoryId = $this->getOriginal('category_id');

        $categorySlug = $categoryId !== null
            ? Category::query()->whereKey($categoryId)->value('slug')
            : null;

        return trim(($categorySlug ? $categorySlug.'/' : '').$slug, '/');
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
                ->orWhere('slug', 'like', $like)
                ->orWhere('code', 'like', $like);
        });
    }
}
