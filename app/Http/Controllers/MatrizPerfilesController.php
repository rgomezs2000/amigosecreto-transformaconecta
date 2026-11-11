<?php

namespace App\Http\Controllers;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Services\AccessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class MatrizPerfilesController extends Controller
{
    /**
     * Módulo que controla el acceso a la matriz.
     */
    private function matrixModule(): Module
    {
        $module = Module::query()
            ->where('slug', 'matriz')
            ->where('is_active', true)
            ->first();

        if ($module === null) {
            abort(404);
        }

        return $module;
    }

    private function ensureAccess(): Module
    {
        $module = $this->matrixModule();

        if (! auth()->user()?->hasModuleAccess($module)) {
            abort(403);
        }

        return $module;
    }

    private function can(string $permission, Module $module): bool
    {
        return (bool) auth()->user()?->permissionsForModule($module)->contains('slug', $permission);
    }

    private function ensureCan(string $permission, Module $module): void
    {
        if (! $this->can($permission, $module)) {
            abort(403);
        }
    }

    public function index(): View
    {
        $module = $this->ensureAccess();

        $roles = Profile::query()->orderBy('name')->get();
        $initialRole = $roles->firstWhere('id', auth()->user()->profile_id) ?? $roles->first();

        return view('matriz.index', [
            'roles' => $roles,
            'initialRole' => $initialRole,
            'canGrant' => $this->can('grant', $module),
            'canRevoke' => $this->can('revoke', $module),
        ]);
    }

    /**
     * Matriz del rol seleccionado: módulos, permisos disponibles y concesiones.
     */
    public function datos(Request $request): JsonResponse
    {
        $module = $this->ensureAccess();

        $validator = Validator::make($request->all(), [
            'profile_id' => ['required', 'integer', 'exists:profiles,id'],
        ], [
            'profile_id.required' => 'Selecciona un rol.',
            'profile_id.exists' => 'El rol seleccionado no existe.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $role = Profile::query()->findOrFail($request->integer('profile_id'));

        $modules = Module::query()
            ->where('is_active', true)
            ->orderBy('group')
            ->orderBy('order')
            ->get();

        $matrix = $modules->map(function (Module $item) use ($role) {
            $granted = ProfilePermissionModule::query()
                ->where('profile_id', $role->id)
                ->where('module_id', $item->id)
                ->pluck('permission_id');

            $available = $item->permissions()->orderBy('name')->get();

            return [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'group' => $item->group,
                'icon' => $item->icon,
                'associated' => $granted->isNotEmpty(),
                'permissions' => $available->map(fn (Permission $permission) => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'slug' => $permission->slug,
                    'granted' => $granted->contains($permission->id),
                ])->values(),
            ];
        });

        return api_success('Matriz cargada.', [
            'profile' => ['id' => $role->id, 'name' => $role->name],
            'modules' => $matrix,
            'can_grant' => $this->can('grant', $module),
            'can_revoke' => $this->can('revoke', $module),
        ]);
    }

    public function asociarModulo(Request $request): JsonResponse
    {
        $module = $this->ensureAccess();
        $this->ensureCan('grant', $module);

        $data = $this->validateRelation($request);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        [$role, $target] = $data;

        $permission = $target->permissions()->where('slug', 'view')->first()
            ?? $target->permissions()->orderBy('name')->first();

        if ($permission === null) {
            return api_error('El módulo no tiene permisos disponibles para asociar.', 422);
        }

        try {
            $created = app(AccessService::class)->grant($role, $target, $permission);

            return api_success(
                $created ? 'Módulo asociado al rol.' : 'El módulo ya estaba asociado.',
                ['associated' => true]
            );
        } catch (Throwable $exception) {
            Log::error('Error al asociar módulo a rol', ['error' => $exception->getMessage()]);

            return api_error('No se pudo asociar el módulo. Intenta de nuevo.', 500);
        }
    }

    public function desasociarModulo(Request $request): JsonResponse
    {
        $module = $this->ensureAccess();
        $this->ensureCan('revoke', $module);

        $data = $this->validateRelation($request);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        [$role, $target] = $data;

        try {
            $count = app(AccessService::class)->revokeModule($role, $target);

            return api_success(
                $count > 0 ? 'Módulo desasociado del rol.' : 'El módulo no estaba asociado.',
                ['associated' => false]
            );
        } catch (Throwable $exception) {
            Log::error('Error al desasociar módulo de rol', ['error' => $exception->getMessage()]);

            return api_error('No se pudo desasociar el módulo. Intenta de nuevo.', 500);
        }
    }

    public function asignarPermiso(Request $request): JsonResponse
    {
        $module = $this->ensureAccess();
        $this->ensureCan('grant', $module);

        $data = $this->validateRelation($request, true);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        [$role, $target, $permission] = $data;

        if (! $target->permissions()->whereKey($permission->id)->exists()) {
            return api_error('El permiso no está disponible para este módulo.', 422);
        }

        try {
            $created = app(AccessService::class)->grant($role, $target, $permission);

            return api_success(
                $created ? 'Permiso asignado.' : 'El permiso ya estaba asignado.',
                ['associated' => true]
            );
        } catch (Throwable $exception) {
            Log::error('Error al asignar permiso', ['error' => $exception->getMessage()]);

            return api_error('No se pudo asignar el permiso. Intenta de nuevo.', 500);
        }
    }

    public function revocarPermiso(Request $request): JsonResponse
    {
        $module = $this->ensureAccess();
        $this->ensureCan('revoke', $module);

        $data = $this->validateRelation($request, true);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        [$role, $target, $permission] = $data;

        try {
            $deleted = app(AccessService::class)->revoke($role, $target, $permission);

            $remaining = ProfilePermissionModule::query()
                ->where('profile_id', $role->id)
                ->where('module_id', $target->id)
                ->exists();

            return api_success(
                $deleted ? 'Permiso revocado.' : 'El permiso no estaba asignado.',
                ['associated' => $remaining]
            );
        } catch (Throwable $exception) {
            Log::error('Error al revocar permiso', ['error' => $exception->getMessage()]);

            return api_error('No se pudo revocar el permiso. Intenta de nuevo.', 500);
        }
    }

    public function aplicar(Request $request): JsonResponse
    {
        $module = $this->ensureAccess();
        $this->ensureCan('grant', $module);
        $this->ensureCan('revoke', $module);

        $validator = Validator::make($request->all(), [
            'profile_id' => ['required', 'integer', 'exists:profiles,id'],
            'modules' => ['required', 'array'],
            'modules.*.module_id' => ['required', 'integer', 'exists:modules,id'],
            'modules.*.permission_ids' => ['array'],
            'modules.*.permission_ids.*' => ['integer', 'exists:permissions,id'],
        ], [
            'profile_id.required' => 'Selecciona un rol.',
            'profile_id.exists' => 'El rol seleccionado no existe.',
            'modules.required' => 'No hay módulos para aplicar.',
            'modules.*.module_id.required' => 'Falta el módulo.',
            'modules.*.module_id.exists' => 'Uno de los módulos no existe.',
            'modules.*.permission_ids.*.exists' => 'Uno de los permisos no existe.',
        ]);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $role = Profile::query()->findOrFail($request->integer('profile_id'));
        $modulesState = [];

        foreach ($request->input('modules') as $state) {
            $target = Module::query()->find($state['module_id'] ?? null);

            if ($target === null || ! $target->is_active) {
                return api_error('El módulo está inactivo o no existe.', 422);
            }

            $available = $target->permissions()->pluck('id');
            $requested = collect($state['permission_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->unique();

            if ($requested->diff($available)->isNotEmpty()) {
                return api_error(
                    'Hay permisos no disponibles para el módulo «' . $target->name . '».',
                    422
                );
            }

            $modulesState[] = [
                'module_id' => $target->id,
                'permission_ids' => $requested->values()->all(),
            ];
        }

        try {
            $result = $role->applyMatrix($modulesState);

            $message = 'Matriz actualizada: '
                . format_count($result['granted']) . ' permiso(s) otorgado(s) y '
                . format_count($result['revoked']) . ' revocado(s).';

            $request->session()->flash('matriz_status', $message);

            return api_success($message, [
                'redirect' => route('matriz.index'),
                'granted' => $result['granted'],
                'revoked' => $result['revoked'],
            ]);
        } catch (Throwable $exception) {
            Log::error('Error al aplicar la matriz', ['error' => $exception->getMessage()]);

            return api_error('No se pudo aplicar la matriz. Intenta de nuevo.', 500);
        }
    }

    /**
     * @return array{0:Profile,1:Module}|array{0:Profile,1:Module,2:Permission}|JsonResponse
     */
    private function validateRelation(Request $request, bool $withPermission = false): array|JsonResponse
    {
        $rules = [
            'profile_id' => ['required', 'integer', 'exists:profiles,id'],
            'module_id' => ['required', 'integer', 'exists:modules,id'],
        ];

        $messages = [
            'profile_id.required' => 'Selecciona un rol.',
            'profile_id.exists' => 'El rol seleccionado no existe.',
            'module_id.required' => 'Selecciona un módulo.',
            'module_id.exists' => 'El módulo seleccionado no existe.',
        ];

        if ($withPermission) {
            $rules['permission_id'] = ['required', 'integer', 'exists:permissions,id'];
            $messages['permission_id.required'] = 'Selecciona un permiso.';
            $messages['permission_id.exists'] = 'El permiso seleccionado no existe.';
        }

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return api_response(false, $validator->errors()->first(), [
                'errors' => $validator->errors(),
            ], 422);
        }

        $role = Profile::query()->findOrFail($request->integer('profile_id'));
        $target = Module::query()->findOrFail($request->integer('module_id'));

        if (! $target->is_active) {
            return api_error('El módulo está inactivo.', 422);
        }

        if ($withPermission) {
            $permission = Permission::query()->findOrFail($request->integer('permission_id'));

            return [$role, $target, $permission];
        }

        return [$role, $target];
    }
}
