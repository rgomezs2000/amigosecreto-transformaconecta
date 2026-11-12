<?php

use App\Models\Category;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use App\Models\UserPermissionModule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Orden de los módulos de "Amigo secreto" en el panel del participante:
     * sorteo, ver tu amigo, lista de deseos, intercambio de pistas y gran
     * revelación. Los módulos de administración van después.
     *
     * @var array<string, int>
     */
    private const ORDER = [
        'asignar-amigo' => 60,
        'ver-tu-amigo' => 70,
        'lista-de-deseos' => 80,
        'intercambio-de-pista' => 90,
        'gran-revelacion' => 100,
        'obsequios' => 110,
        'pistas' => 120,
        'participantes' => 130,
        'usuarios-asociados' => 140,
        'usuarios-obsequiados' => 150,
    ];

    /**
     * Restaura el módulo "Ver tu amigo" en bases ya sembradas y fija el orden
     * de los módulos de "Amigo secreto".
     *
     * El módulo queda solo como ficha del panel, con su página en
     * construcción: su contenido se construye más adelante. Es exclusivo del
     * perfil Usuario, así que la administración no lo ve.
     *
     * En una instalación nueva la tabla está vacía y este paso no hace nada:
     * los seeders crean el módulo, sus permisos y este mismo orden.
     */
    public function up(): void
    {
        $category = Category::query()->where('slug', 'amigo-secreto')->first();

        if ($category === null) {
            return;
        }

        // El módulo se creó como "revelacion" y quedó renombrado a
        // "gran-revelacion". Se unifica el slug para que la ruta sea la misma
        // en cualquier instalación.
        $this->renameSlug('revelacion', 'gran-revelacion', 'Gran revelación');

        $module = Module::query()->updateOrCreate(
            ['slug' => 'ver-tu-amigo'],
            [
                'name' => 'Ver tu amigo',
                'group' => 'Amigo secreto',
                'category_id' => $category->id,
                'icon' => 'fa-user-secret',
                'order' => self::ORDER['ver-tu-amigo'],
                'is_active' => true,
            ]
        );

        $view = Permission::query()->where('slug', 'view')->first();

        if ($view !== null) {
            DB::table('module_permission')->insertOrIgnore([
                'module_id' => $module->id,
                'permission_id' => $view->id,
            ]);

            $usuario = Profile::query()->where('slug', 'usuario')->first();

            if ($usuario !== null) {
                ProfilePermissionModule::query()->firstOrCreate([
                    'profile_id' => $usuario->id,
                    'permission_id' => $view->id,
                    'module_id' => $module->id,
                ]);
            }
        }

        $this->hideFromAdministration($module);

        foreach (self::ORDER as $slug => $order) {
            Module::query()
                ->where('slug', $slug)
                ->where('category_id', $category->id)
                ->update(['order' => $order]);
        }
    }

    public function down(): void
    {
        $module = Module::query()->where('slug', 'ver-tu-amigo')->first();

        if ($module === null) {
            return;
        }

        DB::table('module_permission')->where('module_id', $module->id)->delete();
        DB::table('profile_permission_module')->where('module_id', $module->id)->delete();
        DB::table('user_permission_module')->where('module_id', $module->id)->delete();

        $module->delete();
    }

    /**
     * Quita del perfil Administrador (y de sus usuarios) cualquier acceso a
     * este módulo: es solo del participante.
     */
    private function hideFromAdministration(Module $module): void
    {
        $admin = Profile::query()->where('slug', 'administrador')->first();

        if ($admin === null) {
            return;
        }

        ProfilePermissionModule::query()
            ->where('profile_id', $admin->id)
            ->where('module_id', $module->id)
            ->delete();

        $adminIds = User::query()->where('profile_id', $admin->id)->pluck('id');

        if ($adminIds->isNotEmpty()) {
            UserPermissionModule::query()
                ->whereIn('user_id', $adminIds)
                ->where('module_id', $module->id)
                ->delete();
        }
    }

    /**
     * Cambia el slug de un módulo. Si el slug nuevo ya existe, traspasa los
     * permisos y accesos y elimina el registro antiguo para no duplicar la
     * ficha en el panel.
     */
    private function renameSlug(string $from, string $to, string $name): void
    {
        $legacy = Module::query()->where('slug', $from)->first();

        if ($legacy === null) {
            return;
        }

        $current = Module::query()->where('slug', $to)->first();

        if ($current === null) {
            $legacy->update(['slug' => $to, 'name' => $name]);

            return;
        }

        $permissions = DB::table('module_permission')->where('module_id', $legacy->id)->pluck('permission_id');

        foreach ($permissions as $permissionId) {
            DB::table('module_permission')->insertOrIgnore([
                'module_id' => $current->id,
                'permission_id' => $permissionId,
            ]);
        }

        foreach (DB::table('profile_permission_module')->where('module_id', $legacy->id)->get() as $grant) {
            DB::table('profile_permission_module')->insertOrIgnore([
                'profile_id' => $grant->profile_id,
                'permission_id' => $grant->permission_id,
                'module_id' => $current->id,
            ]);
        }

        foreach (DB::table('user_permission_module')->where('module_id', $legacy->id)->get() as $grant) {
            DB::table('user_permission_module')->insertOrIgnore([
                'user_id' => $grant->user_id,
                'permission_id' => $grant->permission_id,
                'module_id' => $current->id,
                'allowed' => $grant->allowed,
            ]);
        }

        DB::table('module_permission')->where('module_id', $legacy->id)->delete();
        DB::table('profile_permission_module')->where('module_id', $legacy->id)->delete();
        DB::table('user_permission_module')->where('module_id', $legacy->id)->delete();

        $legacy->delete();
    }
};
