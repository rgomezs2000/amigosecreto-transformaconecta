<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use Illuminate\Support\Facades\Auth;

class AccessService
{
    /**
     * Concede un permiso sobre un módulo a un perfil y lo registra.
     */
    public function grant(Profile $profile, Module $module, Permission $permission): bool
    {
        $grant = ProfilePermissionModule::query()->firstOrCreate([
            'profile_id' => $profile->id,
            'module_id' => $module->id,
            'permission_id' => $permission->id,
        ]);

        if ($grant->wasRecentlyCreated) {
            $this->audit('access.granted', $profile, $module, $permission);
        }

        return $grant->wasRecentlyCreated;
    }

    /**
     * Revoca un permiso sobre un módulo a un perfil y lo registra.
     */
    public function revoke(Profile $profile, Module $module, Permission $permission): bool
    {
        $deleted = ProfilePermissionModule::query()
            ->where('profile_id', $profile->id)
            ->where('module_id', $module->id)
            ->where('permission_id', $permission->id)
            ->delete();

        if ($deleted > 0) {
            $this->audit('access.revoked', $profile, $module, $permission);
        }

        return $deleted > 0;
    }

    /**
     * Revoca todos los permisos de un módulo para un perfil.
     */
    public function revokeModule(Profile $profile, Module $module): int
    {
        $permissionIds = ProfilePermissionModule::query()
            ->where('profile_id', $profile->id)
            ->where('module_id', $module->id)
            ->pluck('permission_id');

        $permissions = Permission::query()
            ->whereIn('id', $permissionIds)
            ->get();

        $revoked = 0;

        foreach ($permissions as $permission) {
            if ($this->revoke($profile, $module, $permission)) {
                $revoked++;
            }
        }

        return $revoked;
    }

    private function audit(string $action, Profile $profile, Module $module, Permission $permission): void
    {
        AuditLog::query()->create([
            'user_id' => Auth::id(),
            'action' => $action,
            'payload' => [
                'profile' => $profile->slug,
                'module' => $module->slug,
                'permission' => $permission->slug,
            ],
        ]);
    }
}
