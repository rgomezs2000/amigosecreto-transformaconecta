<?php

namespace App\Console\Commands;

use App\Models\Profile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AccessShow extends Command
{
    protected $signature = 'access:show {profile}';

    protected $description = 'Muestra los permisos guardados en BD para un perfil';

    public function handle(): int
    {
        $profile = Profile::query()->where('slug', $this->argument('profile'))->first();

        if ($profile === null) {
            $this->error('No se encontró el perfil.');

            return self::FAILURE;
        }

        $rows = DB::table('profile_permission_module')
            ->join('modules', 'modules.id', '=', 'profile_permission_module.module_id')
            ->join('permissions', 'permissions.id', '=', 'profile_permission_module.permission_id')
            ->where('profile_id', $profile->id)
            ->orderBy('modules.order')
            ->orderBy('permissions.name')
            ->select('modules.name as module', 'permissions.name as permission')
            ->get();

        $this->info("Perfil: {$profile->name} ({$rows->count()} permisos)");

        $this->table(
            ['Módulo', 'Permiso'],
            $rows->map(fn ($row) => [$row->module, $row->permission])->all(),
        );

        return self::SUCCESS;
    }
}
