<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class PermisosController extends Controller
{
    private function ensureAccess(): void
    {
        $module = Module::activeByCode('permisos');

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

        return view('permisos.index');
    }

    public function datos(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $draw = (int) $request->input('draw', 1);
        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);
        $search = (string) $request->input('search.value', '');

        $recordsTotal = Permission::query()->count();

        $filtered = Permission::query()->search($search);

        $recordsFiltered = (clone $filtered)->count();

        $columnIndex = (int) $request->input('order.0.column', 1);
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $column = match ($columnIndex) {
            2 => 'name',
            default => 'id',
        };

        // Desempate por id: sin él, con nombres repetidos MySQL no garantiza un
        // orden estable entre consultas y, al paginar, una fila puede repetirse
        // o saltarse.
        $query = $filtered->orderBy($column, $direction)->orderBy('id');

        if ($length > 0) {
            $query->skip($start)->take($length);
        }

        $permissions = $query->get();

        $data = $permissions->map(fn (Permission $permission) => [
            'check' => '<input type="checkbox" class="form-check-input permission-checkbox" value="' . $permission->id . '" aria-label="Seleccionar permiso ' . e($permission->name) . '">',
            'id' => $permission->id,
            'name' => e($permission->name),
            'actions' => view('permisos.partials.actions', ['permission' => $permission])->render(),
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
            'name' => ['required', 'string', 'max:60', Rule::unique('permissions', 'name')],
        ], [
            'name.required' => 'El permiso es obligatorio.',
            'name.max' => 'El permiso no puede superar 60 caracteres.',
            'name.unique' => 'Ese permiso ya existe.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            Permission::create([
                'name' => $request->input('name'),
                'slug' => Permission::generateSlug($request->input('name')),
                'description' => null,
            ]);

            $request->session()->flash('permisos_status', 'Permiso creado correctamente.');

            return api_success('Permiso creado correctamente.', [
                'redirect' => module_url('permisos'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al crear el permiso', ['error' => $exception->getMessage()]);

            return api_error('No se pudo crear el permiso. Intenta de nuevo.', 500);
        }
    }

    public function editar(Permission $permission): JsonResponse
    {
        $this->ensureAccess();

        return api_success('Permiso encontrado.', [
            'permission' => $this->permissionData($permission),
        ]);
    }

    public function ver(Permission $permission): JsonResponse
    {
        $this->ensureAccess();

        return api_success('Permiso encontrado.', [
            'permission' => $this->permissionData($permission),
        ]);
    }

    public function modificar(Request $request, Permission $permission): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:60', Rule::unique('permissions', 'name')->ignore($permission->id)],
        ], [
            'name.required' => 'El permiso es obligatorio.',
            'name.max' => 'El permiso no puede superar 60 caracteres.',
            'name.unique' => 'Ese permiso ya existe.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $permission->update(['name' => $request->input('name')]);

            $request->session()->flash('permisos_status', 'Permiso modificado correctamente.');

            return api_success('Permiso modificado correctamente.', [
                'redirect' => module_url('permisos'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al modificar el permiso', [
                'permission_id' => $permission->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo modificar el permiso. Intenta de nuevo.', 500);
        }
    }

    public function eliminar(Request $request, Permission $permission): JsonResponse
    {
        $this->ensureAccess();

        try {
            $blocker = $permission->deletionBlocker();

            if ($blocker !== null) {
                $message = $blocker === 'modules'
                    ? 'No se puede eliminar: el permiso está asociado a módulos. Quita esa asociación antes de eliminarlo.'
                    : 'No se puede eliminar: el permiso está asignado a roles. Revócalo en la Matriz de perfiles antes de eliminarlo.';

                return api_error($message, 409, ['blocked_by' => $blocker]);
            }

            $permission->delete();

            $request->session()->flash('permisos_status', 'Permiso eliminado correctamente.');

            return api_success('Permiso eliminado correctamente.', [
                'redirect' => module_url('permisos'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al eliminar el permiso', [
                'permission_id' => $permission->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo eliminar el permiso. Intenta de nuevo.', 500);
        }
    }

    public function eliminarVarios(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:permissions,id'],
        ], [
            'ids.required' => 'Selecciona al menos un permiso.',
            'ids.*.exists' => 'Uno de los permisos seleccionados no existe.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $permissions = Permission::query()
            ->whereIn('id', $request->input('ids'))
            ->get();

        $deleted = [];
        $blocked = [];

        try {
            foreach ($permissions as $permission) {
                $blocker = $permission->deletionBlocker();

                if ($blocker !== null) {
                    $blocked[] = ['name' => $permission->name, 'reason' => $blocker];
                    continue;
                }

                $permission->delete();
                $deleted[] = $permission->name;
            }
        } catch (Throwable $exception) {
            Log::error('Error al eliminar varios permisos', ['error' => $exception->getMessage()]);

            return api_error('No se pudieron eliminar los permisos. Intenta de nuevo.', 500);
        }

        if ($deleted === []) {
            return api_error(
                'No se pudo eliminar: los permisos seleccionados tienen módulos o roles asociados.',
                409,
                ['blocked' => $blocked]
            );
        }

        $message = count($deleted) . ' permiso(s) eliminado(s).';

        if ($blocked !== []) {
            $reasons = array_map(
                fn (array $item) => $item['name']
                    . ' (' . ($item['reason'] === 'modules' ? 'módulos' : 'roles') . ')',
                $blocked
            );

            $message .= ' No se eliminaron: ' . implode(', ', $reasons) . '.';
        }

        $request->session()->flash('permisos_status', $message);

        return api_success($message, [
            'redirect' => module_url('permisos'),
            'deleted' => $deleted,
            'blocked' => $blocked,
        ]);
    }

    /**
     * @return array{id:int,name:string,slug:string,modules_count:int,roles_count:int}
     */
    private function permissionData(Permission $permission): array
    {
        return [
            'id' => $permission->id,
            'name' => $permission->name,
            'slug' => $permission->slug,
            'modules_count' => $permission->modules()->count(),
            'roles_count' => $permission->profiles()->count(),
        ];
    }
}
