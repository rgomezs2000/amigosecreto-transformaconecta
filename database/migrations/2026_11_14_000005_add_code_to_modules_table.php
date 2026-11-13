<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Código interno y fijo de cada módulo.
     *
     * El slug es mutable: el maestro de Módulos lo cambia y con él cambia la
     * URL pública (/[categoria]/[modulo]). El código, en cambio, no cambia
     * nunca: es la identidad del módulo y lo que permite que las páginas, los
     * accesos y el menú lo sigan reconociendo después de renombrar su slug o
     * su nombre.
     *
     * Los módulos creados a partir de ahora reciben un código derivado del
     * slug en el modelo Module (ver Module::booted()); aquí solo se rellenan
     * los módulos que ya existían.
     */
    public function up(): void
    {
        if (! Schema::hasTable('modules') || Schema::hasColumn('modules', 'code')) {
            return;
        }

        Schema::table('modules', function (Blueprint $table) {
            $table->string('code', 80)->nullable()->after('slug');
        });

        // Slugs históricos que llegaron a estar en la base de datos antes de
        // que el maestro de Módulos normalizara los slugs (ver migración
        // 2026_11_10_000017_add_slugs_and_route_structure).
        $legacy = [
            '' => 'inicio',
            'inicio' => 'inicio',
            'perfiles' => 'perfil',
            'perfil' => 'perfil',
            'maestros.roles' => 'roles',
            'maestros.permisos' => 'permisos',
            'maestros.modulos' => 'modulos',
            'maestros.matriz' => 'matriz',
            'maestros.categorias' => 'categorias',
            'amigos.asignar' => 'asignar-amigo',
            'amigos.ver' => 'ver-tu-amigo',
            'amigos.deseos' => 'lista-de-deseos',
            'amigos.intercambio' => 'intercambio-de-pista',
            'amigos.revelacion' => 'gran-revelacion',
            'amigos.obsequios' => 'obsequios',
            'amigos.notificaciones' => 'notificaciones',
            'amigo.participantes' => 'participantes',
            'amigo.asociado' => 'usuarios-asociados',
            'amigo.obsequiados' => 'usuarios-obsequiados',
            'salir' => 'logout',
        ];

        $used = [];

        foreach (DB::table('modules')->orderBy('id')->get(['id', 'slug']) as $module) {
            $slug = (string) $module->slug;
            $code = $legacy[$slug] ?? ($slug !== '' ? $slug : 'inicio');

            // Nunca dos módulos con el mismo código: el segundo conserva su
            // slug (o su id) como código.
            if (isset($used[$code])) {
                $code = $slug !== '' ? $slug : 'inicio-'.$module->id;
            }

            if (isset($used[$code])) {
                $code = 'modulo-'.$module->id;
            }

            $used[$code] = true;

            DB::table('modules')->where('id', $module->id)->update(['code' => $code]);
        }

        Schema::table('modules', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('modules') || ! Schema::hasColumn('modules', 'code')) {
            return;
        }

        Schema::table('modules', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
