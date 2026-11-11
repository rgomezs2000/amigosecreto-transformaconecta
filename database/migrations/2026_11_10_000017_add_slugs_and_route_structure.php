<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estructura de rutas /[categoria]/[modulo]:
     * - El slug de la categoría es el primer eslabón.
     * - El slug del módulo es el último eslabón.
     * - General y Sesión no llevan slug de categoría.
     * - Inicio queda con slug vacío (raíz) y Salir con logout.
     */
    public function up(): void
    {
        if (Schema::hasTable('categories')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('slug', 80)->nullable()->change();
            });
        }

        $categorySlugs = [
            'General' => null,
            'Maestros' => 'maestros',
            'Usuarios' => 'usuarios',
            'Eventos' => 'eventos',
            'Amigo secreto' => 'amigo-secreto',
            'Notificaciones' => 'notificaciones',
            'Sesión' => null,
        ];

        foreach ($categorySlugs as $name => $slug) {
            DB::table('categories')
                ->where('name', $name)
                ->update(['slug' => $slug, 'updated_at' => now()]);
        }

        // Las dos "Lista de deseos" (participante y administrador) comparten
        // ruta final; se unifican en el módulo del participante.
        $canonical = DB::table('modules')->where('slug', 'amigos.deseos')->first();
        $duplicate = DB::table('modules')->where('slug', 'amigo.deseos')->first();

        if ($canonical !== null && $duplicate !== null) {
            foreach (DB::table('profile_permission_module')->where('module_id', $duplicate->id)->get() as $row) {
                DB::table('profile_permission_module')->insertOrIgnore([
                    'profile_id' => $row->profile_id,
                    'permission_id' => $row->permission_id,
                    'module_id' => $canonical->id,
                ]);
            }

            DB::table('profile_permission_module')->where('module_id', $duplicate->id)->delete();

            foreach (DB::table('module_permission')->where('module_id', $duplicate->id)->get() as $row) {
                DB::table('module_permission')->insertOrIgnore([
                    'module_id' => $canonical->id,
                    'permission_id' => $row->permission_id,
                ]);
            }

            DB::table('module_permission')->where('module_id', $duplicate->id)->delete();
            DB::table('modules')->where('id', $duplicate->id)->delete();
        }

        $moduleSlugs = [
            'inicio' => '',
            'perfiles' => 'perfiles',
            'maestros.roles' => 'roles',
            'maestros.permisos' => 'permisos',
            'maestros.modulos' => 'modulos',
            'maestros.matriz' => 'matriz',
            'maestros.categorias' => 'categorias',
            'usuarios' => 'usuarios',
            'eventos' => 'eventos',
            'amigos.asignar' => 'asignar-amigo',
            'amigos.ver' => 'ver-tu-amigo',
            'amigos.obsequios' => 'obsequios',
            'amigos.deseos' => 'lista-de-deseos',
            'amigos.intercambio' => 'intercambio-de-pista',
            'amigos.revelacion' => 'revelacion',
            'amigos.notificaciones' => 'notificaciones',
            'amigo.participantes' => 'participantes',
            'amigo.asociado' => 'usuarios-asociados',
            'amigo.obsequiados' => 'usuarios-obsequiados',
            'salir' => 'logout',
        ];

        foreach ($moduleSlugs as $old => $new) {
            DB::table('modules')
                ->where('slug', $old)
                ->update(['slug' => $new, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        //
    }
};
