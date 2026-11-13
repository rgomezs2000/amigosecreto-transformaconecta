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

class CategoriasController extends Controller
{
    /**
     * Solo el perfil con acceso al módulo de Categorías entra al maestro.
     */
    private function ensureAccess(): void
    {
        $module = Module::activeByCode('categorias');

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

        return view('categorias.index');
    }

    public function datos(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $draw = (int) $request->input('draw', 1);
        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);
        $search = (string) $request->input('search.value', '');

        $recordsTotal = Category::query()->count();

        $filtered = Category::query()->search($search);

        $recordsFiltered = (clone $filtered)->count();

        $columnIndex = (int) $request->input('order.0.column', 2);
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $column = match ($columnIndex) {
            2 => 'name',
            default => 'name',
        };

        // Desempate por id: sin él, con nombres repetidos MySQL no garantiza un
        // orden estable entre consultas y, al paginar, una fila puede repetirse
        // o saltarse.
        $query = $filtered->orderBy($column, $direction)->orderBy('id');

        if ($length > 0) {
            $query->skip($start)->take($length);
        }

        $categories = $query->get();

        $data = $categories->map(fn (Category $category) => [
            'check' => '<input type="checkbox" class="form-check-input category-checkbox" value="' . $category->id . '" aria-label="Seleccionar categoría ' . e($category->name) . '">',
            'id' => $category->id,
            'name' => e($category->name),
            'slug' => $category->slug !== null ? e($category->slug) : '—',
            'actions' => view('categorias.partials.actions', ['category' => $category])->render(),
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
            'name' => ['required', 'string', 'max:60', Rule::unique('categories', 'name')],
            'slug' => [
                'required', 'string', 'max:80',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('categories', 'slug'),
            ],
        ], [
            'name.required' => 'La categoría es obligatoria.',
            'name.max' => 'La categoría no puede superar 60 caracteres.',
            'name.unique' => 'Ese nombre de categoría ya existe.',
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
            Category::create([
                'name' => $request->input('name'),
                'slug' => Str::slug($request->input('slug')),
                'sort_order' => (int) Category::query()->max('sort_order') + 1,
                'is_active' => true,
            ]);

            $request->session()->flash('categorias_status', 'Categoría creada correctamente.');

            return api_success('Categoría creada correctamente.', [
                'redirect' => module_url('categorias'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al crear la categoría', ['error' => $exception->getMessage()]);

            return api_error('No se pudo crear la categoría. Intenta de nuevo.', 500);
        }
    }

    public function editar(Category $category): JsonResponse
    {
        $this->ensureAccess();

        return api_success('Categoría encontrada.', [
            'category' => $this->categoryData($category),
        ]);
    }

    public function ver(Category $category): JsonResponse
    {
        $this->ensureAccess();

        return api_success('Categoría encontrada.', [
            'category' => $this->categoryData($category),
        ]);
    }

    public function modificar(Request $request, Category $category): JsonResponse
    {
        $this->ensureAccess();

        $isSystem = in_array($category->name, ['General', 'Sesión'], true)
            || in_array($request->input('name'), ['General', 'Sesión'], true);

        $slugRules = $isSystem
            ? ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9]*(?:-[a-z0-9]+)*$/']
            : ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('categories', 'slug')->ignore($category->id)];

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:60', Rule::unique('categories', 'name')->ignore($category->id)],
            'slug' => $slugRules,
        ], [
            'name.required' => 'La categoría es obligatoria.',
            'name.max' => 'La categoría no puede superar 60 caracteres.',
            'name.unique' => 'Ese nombre de categoría ya existe.',
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
            $category->update([
                'name' => $request->input('name'),
                'slug' => $isSystem ? null : Str::slug($request->input('slug')),
            ]);

            $request->session()->flash('categorias_status', 'Categoría modificada correctamente.');

            return api_success('Categoría modificada correctamente.', [
                'redirect' => module_url('categorias'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al modificar la categoría', [
                'category_id' => $category->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo modificar la categoría. Intenta de nuevo.', 500);
        }
    }

    public function eliminar(Request $request, Category $category): JsonResponse
    {
        $this->ensureAccess();

        try {
            $blocker = $category->deletionBlocker();

            if ($blocker !== null) {
                return api_error(
                    'No se puede eliminar: la categoría tiene módulos asociados. Reasigna esos módulos a otra categoría antes de eliminarla.',
                    409,
                    ['blocked_by' => $blocker]
                );
            }

            $category->delete();

            $request->session()->flash('categorias_status', 'Categoría eliminada correctamente.');

            return api_success('Categoría eliminada correctamente.', [
                'redirect' => module_url('categorias'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al eliminar la categoría', [
                'category_id' => $category->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo eliminar la categoría. Intenta de nuevo.', 500);
        }
    }

    public function eliminarVarios(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:categories,id'],
        ], [
            'ids.required' => 'Selecciona al menos una categoría.',
            'ids.*.exists' => 'Una de las categorías seleccionadas no existe.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $categories = Category::query()
            ->whereIn('id', $request->input('ids'))
            ->get();

        $deleted = [];
        $blocked = [];

        try {
            foreach ($categories as $category) {
                $blocker = $category->deletionBlocker();

                if ($blocker !== null) {
                    $blocked[] = ['name' => $category->name, 'reason' => $blocker];
                    continue;
                }

                $category->delete();
                $deleted[] = $category->name;
            }
        } catch (Throwable $exception) {
            Log::error('Error al eliminar varias categorías', ['error' => $exception->getMessage()]);

            return api_error('No se pudieron eliminar las categorías. Intenta de nuevo.', 500);
        }

        if ($deleted === []) {
            return api_error(
                'No se pudo eliminar: las categorías seleccionadas tienen módulos asociados.',
                409,
                ['blocked' => $blocked]
            );
        }

        $message = count($deleted) . ' categoría(s) eliminada(s).';

        if ($blocked !== []) {
            $reasons = array_map(
                fn (array $item) => $item['name'] . ' (módulos)',
                $blocked
            );

            $message .= ' No se eliminaron: ' . implode(', ', $reasons) . '.';
        }

        $request->session()->flash('categorias_status', $message);

        return api_success($message, [
            'redirect' => module_url('categorias'),
            'deleted' => $deleted,
            'blocked' => $blocked,
        ]);
    }

    /**
     * @return array{id:int,name:string,slug:string,modules_count:int,modules:array<int,string>}
     */
    private function categoryData(Category $category): array
    {
        $modules = $category->modules()->orderBy('order')->get();

        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'modules_count' => $modules->count(),
            'modules' => $modules->pluck('name')->all(),
        ];
    }
}
