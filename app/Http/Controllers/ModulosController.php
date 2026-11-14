<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Module;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ModulosController extends Controller
{
    private function ensureAccess(): void
    {
        $module = Module::activeByCode('modulos');

        if ($module === null) {
            abort(404);
        }

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }
    }

    /**
     * Iconos sugeridos en el formulario. La base de datos acepta cualquier
     * clase de Font Awesome; esta lista solo sirve de atajo.
     *
     * @var array<int, string>
     */
    private const ICON_SUGGESTIONS = [
        'fa-house',
        'fa-users',
        'fa-user',
        'fa-user-shield',
        'fa-users-gear',
        'fa-key',
        'fa-lock',
        'fa-cubes',
        'fa-table-cells',
        'fa-tags',
        'fa-list',
        'fa-calendar-days',
        'fa-handshake',
        'fa-user-secret',
        'fa-gift',
        'fa-bell',
        'fa-bell-slash',
        'fa-comments',
        'fa-envelope',
        'fa-id-card',
        'fa-chart-simple',
        'fa-shield-halved',
        'fa-arrows-rotate',
        'fa-lightbulb',
        'fa-star',
        'fa-heart',
        'fa-trophy',
        'fa-circle-info',
        'fa-gear',
        'fa-right-from-bracket',
    ];

    public function index(): View
    {
        $this->ensureAccess();

        return view('modulos.index', [
            'categories' => Category::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'iconSuggestions' => self::ICON_SUGGESTIONS,
        ]);
    }

    public function datos(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $draw = (int) $request->input('draw', 1);
        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);
        $search = (string) $request->input('search.value', '');
        $filterType = (string) $request->input('filter_type', 'all');
        $filterCategory = (int) $request->input('filter_category', 0);

        $recordsTotal = Module::query()->count();

        $filtered = Module::query()->with('category')->search($search);

        if ($filterType === 'category' && $filterCategory > 0) {
            $filtered->where('category_id', $filterCategory);
        }

        $recordsFiltered = (clone $filtered)->count();

        $columnIndex = (int) $request->input('order.0.column', 2);
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $column = match ($columnIndex) {
            5 => 'slug',
            default => 'name',
        };

        // Desempate por id: sin él, con nombres repetidos MySQL no garantiza un
        // orden estable entre consultas y, al paginar, una fila puede repetirse
        // o saltarse.
        $query = $filtered->orderBy($column, $direction)->orderBy('id');

        if ($length > 0) {
            $query->skip($start)->take($length);
        }

        $modules = $query->get();

        $data = $modules->map(function (Module $module) {
            // Inicio, Login y Salir son del sistema: se listan, pero no se
            // pueden seleccionar, modificar ni eliminar.
            $readOnly = $module->isReadOnly();

            $name = e($module->name);

            if ($readOnly) {
                $name .= ' <span class="badge perfil-badge module-readonly-badge">'
                    . '<i class="fa-solid fa-lock" aria-hidden="true"></i> Solo lectura</span>';
            }

            return [
                'check' => $readOnly
                    ? '<span class="module-readonly" title="Módulo del sistema: solo lectura">'
                        . '<i class="fa-solid fa-lock" aria-hidden="true"></i>'
                        . '<span class="visually-hidden">Solo lectura</span></span>'
                    : '<input type="checkbox" class="form-check-input module-checkbox" value="' . $module->id . '" aria-label="Seleccionar módulo ' . e($module->name) . '">',
                'id' => $module->id,
                'name' => $name,
                'category' => e($module->category?->name ?? '—'),
                'icon' => '<span class="module-icon-cell"><i class="fa-solid ' . e($module->icon) . '" aria-hidden="true"></i><code>' . e($module->icon) . '</code></span>',
                'slug' => $module->slug !== '' ? e($module->slug) : '—',
                'actions' => view('modulos.partials.actions', ['module' => $module])->render(),
                'read_only' => $readOnly,
            ];
        });

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ]);
    }

    public function crear(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:80'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'icon' => ['nullable', 'string', 'max:60', 'regex:/^fa-[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'slug' => [
                'required', 'string', 'max:80',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('modules', 'slug'),
            ],
        ], [
            'name.required' => 'El nombre del módulo es obligatorio.',
            'category_id.required' => 'La categoría es obligatoria.',
            'category_id.exists' => 'La categoría seleccionada no existe.',
            'icon.regex' => 'El icono debe ser una clase de Font Awesome, por ejemplo fa-house.',
            'slug.required' => 'El slug es obligatorio.',
            'slug.regex' => 'El slug solo admite minúsculas, números y guiones.',
            'slug.unique' => 'Ese slug ya está en uso.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $slug = Str::slug($request->input('slug'));

            Module::create([
                'name' => $request->input('name'),
                'slug' => $slug,
                'category_id' => (int) $request->input('category_id'),
                'group' => Category::query()->find((int) $request->input('category_id'))?->name,
                'icon' => Module::normalizeIcon($request->input('icon')),
                'order' => (int) Module::query()->max('order') + 1,
                'is_active' => true,
            ]);

            $request->session()->flash('modulos_status', 'Módulo creado correctamente.');

            return api_success('Módulo creado correctamente.', [
                'redirect' => module_url('modulos'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al crear el módulo', ['error' => $exception->getMessage()]);

            return api_error('No se pudo crear el módulo. Intenta de nuevo.', 500);
        }
    }

    public function editar(Module $module): JsonResponse
    {
        $this->ensureAccess();

        if ($module->isReadOnly()) {
            return api_error($this->readOnlyMessage($module), 403);
        }

        return api_success('Módulo encontrado.', [
            'module' => $this->moduleData($module),
        ]);
    }

    public function ver(Module $module): JsonResponse
    {
        $this->ensureAccess();

        return api_success('Módulo encontrado.', [
            'module' => $this->moduleData($module),
        ]);
    }

    public function modificar(Request $request, Module $module): JsonResponse
    {
        $this->ensureAccess();

        if ($module->isReadOnly()) {
            return api_error($this->readOnlyMessage($module), 403);
        }

        $isInicio = $module->slug === '';

        $slugRules = $isInicio
            ? ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]*(?:-[a-z0-9]+)*$/']
            : ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('modules', 'slug')->ignore($module->id)];

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:80'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'icon' => ['nullable', 'string', 'max:60', 'regex:/^fa-[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'slug' => $slugRules,
        ], [
            'name.required' => 'El nombre del módulo es obligatorio.',
            'category_id.required' => 'La categoría es obligatoria.',
            'category_id.exists' => 'La categoría seleccionada no existe.',
            'icon.regex' => 'El icono debe ser una clase de Font Awesome, por ejemplo fa-house.',
            'slug.required' => 'El slug es obligatorio.',
            'slug.regex' => 'El slug solo admite minúsculas, números y guiones.',
            'slug.unique' => 'Ese slug ya está en uso.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $category = Category::query()->findOrFail((int) $request->input('category_id'));

            $module->update([
                'name' => $request->input('name'),
                'slug' => $isInicio ? '' : Str::slug($request->input('slug')),
                'category_id' => $category->id,
                'group' => $category->name,
                'icon' => Module::normalizeIcon($request->input('icon')),
            ]);

            $request->session()->flash('modulos_status', 'Módulo modificado correctamente.');

            return api_success('Módulo modificado correctamente.', [
                'redirect' => module_url('modulos'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al modificar el módulo', [
                'module_id' => $module->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo modificar el módulo. Intenta de nuevo.', 500);
        }
    }

    public function eliminar(Request $request, Module $module): JsonResponse
    {
        $this->ensureAccess();

        try {
            $blocker = $module->deletionBlocker();

            if ($blocker !== null) {
                $message = match ($blocker) {
                    'system' => 'No se puede eliminar: «Inicio», «Login» y «Salir» son módulos del sistema y solo se pueden consultar.',
                    'access' => 'No se puede eliminar: el módulo tiene accesos de roles o usuarios. Revócalos en la Matriz de perfiles antes de eliminarlo.',
                    default => 'No se puede eliminar: el módulo tiene permisos asociados. Quita esos permisos antes de eliminarlo.',
                };

                return api_error($message, 409, ['blocked_by' => $blocker]);
            }

            $module->delete();

            $request->session()->flash('modulos_status', 'Módulo eliminado correctamente.');

            return api_success('Módulo eliminado correctamente.', [
                'redirect' => module_url('modulos'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al eliminar el módulo', [
                'module_id' => $module->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo eliminar el módulo. Intenta de nuevo.', 500);
        }
    }

    public function eliminarVarios(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:modules,id'],
        ], [
            'ids.required' => 'Selecciona al menos un módulo.',
            'ids.*.exists' => 'Uno de los módulos seleccionados no existe.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $modules = Module::query()
            ->whereIn('id', $request->input('ids'))
            ->get();

        $deleted = [];
        $blocked = [];

        try {
            foreach ($modules as $module) {
                $blocker = $module->deletionBlocker();

                if ($blocker !== null) {
                    $blocked[] = ['name' => $module->name, 'reason' => $blocker];
                    continue;
                }

                $module->delete();
                $deleted[] = $module->name;
            }
        } catch (Throwable $exception) {
            Log::error('Error al eliminar varios módulos', ['error' => $exception->getMessage()]);

            return api_error('No se pudieron eliminar los módulos. Intenta de nuevo.', 500);
        }

        if ($deleted === []) {
            return api_error(
                'No se pudo eliminar: los módulos seleccionados son del sistema o tienen accesos o permisos asociados.',
                409,
                ['blocked' => $blocked]
            );
        }

        $message = count($deleted) . ' módulo(s) eliminado(s).';

        if ($blocked !== []) {
            $reasonLabels = [
                'system' => 'sistema',
                'access' => 'accesos',
                'permissions' => 'permisos',
            ];

            $reasons = array_map(
                fn (array $item) => $item['name'] . ' (' . ($reasonLabels[$item['reason']] ?? $item['reason']) . ')',
                $blocked
            );

            $message .= ' No se eliminaron: ' . implode(', ', $reasons) . '.';
        }

        $request->session()->flash('modulos_status', $message);

        return api_success($message, [
            'redirect' => module_url('modulos'),
            'deleted' => $deleted,
            'blocked' => $blocked,
        ]);
    }

    /**
     * Mensaje común de los módulos del sistema: solo se pueden consultar.
     */
    private function readOnlyMessage(Module $module): string
    {
        return '«' . $module->name . '» es un módulo del sistema: solo se puede consultar.';
    }

    /**
     * @return array{id:int,name:string,slug:string,icon:string,category_id:int,category_name:string,category_slug:?string,url:string,permissions_count:int,access_count:int}
     */
    private function moduleData(Module $module): array
    {
        return [
            'id' => $module->id,
            'name' => $module->name,
            'slug' => $module->slug,
            'icon' => $module->icon,
            'category_id' => $module->category_id,
            'category_name' => $module->category?->name,
            'category_slug' => $module->category?->slug,
            'url' => $module->url(),
            'permissions_count' => $module->permissions()->count(),
            'access_count' => $module->profilePermissions()->count(),
        ];
    }
}
