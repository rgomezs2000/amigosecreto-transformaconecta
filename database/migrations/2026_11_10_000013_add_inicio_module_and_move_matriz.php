<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega el módulo "Inicio" (solo menú) y mueve "Matriz de perfiles"
     * al final del grupo Maestros.
     */
    public function up(): void
    {
        if (DB::table('modules')->doesntExist()
            || DB::table('permissions')->doesntExist()
            || DB::table('profiles')->doesntExist()) {
            return;
        }

        $now = now();

        DB::table('modules')
            ->where('slug', 'maestros.matriz')
            ->update(['order' => 35, 'updated_at' => $now]);

        $inicioId = DB::table('modules')->where('slug', 'inicio')->value('id');

        if ($inicioId === null) {
            $inicioId = DB::table('modules')->insertGetId([
                'name' => 'Inicio',
                'slug' => 'inicio',
                'group' => 'General',
                'icon' => 'fa-house',
                'order' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $viewId = DB::table('permissions')->where('slug', 'view')->value('id');

        if ($viewId === null) {
            return;
        }

        DB::table('module_permission')->insertOrIgnore([
            'module_id' => $inicioId,
            'permission_id' => $viewId,
        ]);

        foreach (['administrador', 'usuario'] as $slug) {
            $profileId = DB::table('profiles')->where('slug', $slug)->value('id');

            if ($profileId !== null) {
                DB::table('profile_permission_module')->insertOrIgnore([
                    'profile_id' => $profileId,
                    'module_id' => $inicioId,
                    'permission_id' => $viewId,
                ]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
