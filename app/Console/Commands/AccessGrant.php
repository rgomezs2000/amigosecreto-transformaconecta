<?php

namespace App\Console\Commands;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Services\AccessService;
use Illuminate\Console\Command;

class AccessGrant extends Command
{
    protected $signature = 'access:grant {profile} {module} {permission}';

    protected $description = 'Concede un permiso sobre un módulo a un perfil';

    public function handle(AccessService $access): int
    {
        $profile = Profile::query()->where('slug', $this->argument('profile'))->first();
        $module = Module::query()->where('slug', $this->argument('module'))->first();
        $permission = Permission::query()->where('slug', $this->argument('permission'))->first();

        if ($profile === null || $module === null || $permission === null) {
            $this->error('No se encontró el perfil, el módulo o el permiso.');

            return self::FAILURE;
        }

        $created = $access->grant($profile, $module, $permission);

        $this->info($created
            ? "Permiso concedido: {$permission->name} sobre {$module->name} a {$profile->name}."
            : 'Ese permiso ya estaba concedido.');

        return self::SUCCESS;
    }
}
