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

    public function index(): View
    {
        $this->ensureAccess();

        return view('modulos.index', [
            'categories' => Category::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
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
            4 => 'slug',
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

        $data = $modules->map(fn (Module $module) => [
            'check' => '<input type="checkbox" class="form-check-input module-checkbox" value="' . $module->id . '" aria-label="Seleccionar módulo ' . e($module->name) . '">',
            'id' => $module->id,
            'name' => e($module->name),
            'category' => e($module->category?->name ?? '—'),
            'slug' => $module->slug !== '' ? e($module->slug) : '—',
            'actions' => view('modulos.partials.actions', ['module' => $module])->render(),
        ]);

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
            'slug' => [
                'required', 'string', 'max:80',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('modules', 'slug'),
            ],
        ], [
            'name.required' => 'El nombre del módulo es obligatorio.',
            'category_id.required' => 'La categoría es obligatoria.',
            'category_id.exists' => 'La categoría seleccionada no existe.',
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
                'icon' => 'fa-cube',
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

        $isInicio = $module->slug === '';

        $slugRules = $isInicio
            ? ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]*(?:-[a-z0-9]+)*$/']
            : ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('modules', 'slug')->ignore($module->id)];

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:80'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'slug' => $slugRules,
        ], [
            'name.required' => 'El nombre del módulo es obligatorio.',
            'category_id.required' => 'La categoría es obligatoria.',
            'category_id.exists' => 'La categoría seleccionada no existe.',
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
                $message = $blocker === 'access'
                    ? 'No se puede eliminar: el módulo tiene accesos de roles o usuarios. Revócalos en la Matriz de perfiles antes de eliminarlo.'
                    : 'No se puede eliminar: el módulo tiene permisos asociados. Quita esos permisos antes de eliminarlo.';

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
                'No se pudo eliminar: los módulos seleccionados tienen accesos o permisos asociados.',
                409,
                ['blocked' => $blocked]
            );
        }

        $message = count($deleted) . ' módulo(s) eliminado(s).';

        if ($blocked !== []) {
            $reasons = array_map(
                fn (array $item) => $item['name'] . ' (' . ($item['reason'] === 'access' ? 'accesos' : 'permisos') . ')',
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
     * @return array{id:int,name:string,slug:string,category_id:int,category_name:string,category_slug:?string,url:string,permissions_count:int,access_count:int}
     */
    private function moduleData(Module $module): array
    {
        return [
            'id' => $module->id,
            'name' => $module->name,
            'slug' => $module->slug,
            'category_id' => $module->category_id,
            'category_name' => $module->category?->name,
            'category_slug' => $module->category?->slug,
            'url' => $module->url(),
            'permissions_count' => $module->permissions()->count(),
            'access_count' => $module->profilePermissions()->count(),
        ];
    }
}
