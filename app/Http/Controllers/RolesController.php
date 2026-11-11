<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Profile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class RolesController extends Controller
{
    /**
     * Solo el perfil que tenga el módulo de Roles entra al maestro.
     */
    private function ensureAccess(): void
    {
        $module = Module::query()
            ->where('slug', 'roles')
            ->where('is_active', true)
            ->first();

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

        return view('roles.index');
    }

    /**
     * Datos para el DataTable server-side, con búsqueda y paginación.
     */
    public function datos(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $draw = (int) $request->input('draw', 1);
        $start = max(0, (int) $request->input('start', 0));
        $length = (int) $request->input('length', 10);
        $search = (string) $request->input('search.value', '');

        $recordsTotal = Profile::query()->count();

        $filtered = Profile::query()->search($search);

        $recordsFiltered = (clone $filtered)->count();

        $columnIndex = (int) $request->input('order.0.column', 1);
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $column = match ($columnIndex) {
            2 => 'name',
            default => 'id',
        };

        $query = $filtered->orderBy($column, $direction);

        if ($length > 0) {
            $query->skip($start)->take($length);
        }

        $roles = $query->get();

        $data = $roles->map(fn (Profile $role) => [
            'check' => '<input type="checkbox" class="form-check-input role-checkbox" value="' . $role->id . '" aria-label="Seleccionar rol ' . e($role->name) . '">',
            'id' => $role->id,
            'name' => e($role->name),
            'actions' => view('roles.partials.actions', ['role' => $role])->render(),
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
            'name' => ['required', 'string', 'max:255', Rule::unique('profiles', 'name')],
        ], [
            'name.required' => 'El rol es obligatorio.',
            'name.unique' => 'Ese rol ya existe.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            Profile::create([
                'name' => $request->input('name'),
                'slug' => Profile::generateSlug($request->input('name')),
                'description' => null,
                'is_active' => true,
            ]);

            $request->session()->flash('roles_status', 'Rol creado correctamente.');

            return api_success('Rol creado correctamente.', [
                'redirect' => route('roles.index'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al crear el rol', ['error' => $exception->getMessage()]);

            return api_error('No se pudo crear el rol. Intenta de nuevo.', 500);
        }
    }

    public function editar(Profile $role): JsonResponse
    {
        $this->ensureAccess();

        return api_success('Rol encontrado.', [
            'role' => $this->roleData($role),
        ]);
    }

    public function ver(Profile $role): JsonResponse
    {
        $this->ensureAccess();

        return api_success('Rol encontrado.', [
            'role' => $this->roleData($role),
        ]);
    }

    public function modificar(Request $request, Profile $role): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255', Rule::unique('profiles', 'name')->ignore($role->id)],
        ], [
            'name.required' => 'El rol es obligatorio.',
            'name.unique' => 'Ese rol ya existe.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $role->update(['name' => $request->input('name')]);

            $request->session()->flash('roles_status', 'Rol modificado correctamente.');

            return api_success('Rol modificado correctamente.', [
                'redirect' => route('roles.index'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al modificar el rol', [
                'role_id' => $role->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo modificar el rol. Intenta de nuevo.', 500);
        }
    }

    public function eliminar(Request $request, Profile $role): JsonResponse
    {
        $this->ensureAccess();

        try {
            $blocker = $role->deletionBlocker();

            if ($blocker !== null) {
                $message = $blocker === 'users'
                    ? 'No se puede eliminar: el rol está asignado a usuarios. Reasigna esos usuarios antes de eliminarlo.'
                    : 'No se puede eliminar: el rol tiene módulos o permisos asociados. Revócalos en la Matriz de perfiles antes de eliminarlo.';

                return api_error($message, 409, ['blocked_by' => $blocker]);
            }

            $role->delete();

            $request->session()->flash('roles_status', 'Rol eliminado correctamente.');

            return api_success('Rol eliminado correctamente.', [
                'redirect' => route('roles.index'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al eliminar el rol', [
                'role_id' => $role->id,
                'error' => $exception->getMessage(),
            ]);

            return api_error('No se pudo eliminar el rol. Intenta de nuevo.', 500);
        }
    }

    public function eliminarVarios(Request $request): JsonResponse
    {
        $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:profiles,id'],
        ], [
            'ids.required' => 'Selecciona al menos un rol.',
            'ids.*.exists' => 'Uno de los roles seleccionados no existe.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $roles = Profile::query()
            ->whereIn('id', $request->input('ids'))
            ->get();

        $deleted = [];
        $blocked = [];

        try {
            foreach ($roles as $role) {
                $blocker = $role->deletionBlocker();

                if ($blocker !== null) {
                    $blocked[] = ['name' => $role->name, 'reason' => $blocker];
                    continue;
                }

                $role->delete();
                $deleted[] = $role->name;
            }
        } catch (Throwable $exception) {
            Log::error('Error al eliminar varios roles', ['error' => $exception->getMessage()]);

            return api_error('No se pudieron eliminar los roles. Intenta de nuevo.', 500);
        }

        if ($deleted === []) {
            return api_error(
                'No se pudo eliminar: los roles seleccionados tienen usuarios o accesos asociados.',
                409,
                ['blocked' => $blocked]
            );
        }

        $message = count($deleted) . ' rol(es) eliminado(s).';

        if ($blocked !== []) {
            $reasons = array_map(
                fn (array $item) => $item['name']
                    . ' (' . ($item['reason'] === 'users' ? 'usuarios' : 'permisos') . ')',
                $blocked
            );

            $message .= ' No se eliminaron: ' . implode(', ', $reasons) . '.';
        }

        $request->session()->flash('roles_status', $message);

        return api_success($message, [
            'redirect' => route('roles.index'),
            'deleted' => $deleted,
            'blocked' => $blocked,
        ]);
    }

    /**
     * @return array{id:int,name:string,slug:string,users_count:int,permissions_count:int}
     */
    private function roleData(Profile $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'slug' => $role->slug,
            'users_count' => $role->users()->count(),
            'permissions_count' => $role->permissionModules()->count(),
        ];
    }
}
