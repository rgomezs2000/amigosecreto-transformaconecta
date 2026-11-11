<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\ProfilePermissionModule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MatrizPerfilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            've.dolarapi.com/*' => Http::response([
                'fuente' => 'oficial',
                'nombre' => 'Dólar',
                'compra' => null,
                'venta' => null,
                'promedio' => 899.2705,
                'fechaActualizacion' => '2026-11-10T00:00:00-04:00',
            ]),
        ]);
    }

    private function permiso(string $name, string $slug): Permission
    {
        return Permission::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $name]
        );
    }

    private function matrizModule(): Module
    {
        return Module::create([
            'name' => 'Matriz de perfiles',
            'slug' => 'matriz',
            'group' => 'Maestros',
            'icon' => 'fa-table-cells',
            'order' => 15,
            'is_active' => true,
        ]);
    }

    private function adminWithMatriz(): User
    {
        $profile = Profile::create([
            'name' => 'Administrador',
            'slug' => 'administrador',
            'is_active' => true,
        ]);

        $module = $this->matrizModule();

        foreach (['view' => 'Ver', 'grant' => 'Otorgar', 'revoke' => 'Revocar'] as $slug => $name) {
            $permission = $this->permiso($name, $slug);

            DB::table('module_permission')->insertOrIgnore([
                'module_id' => $module->id,
                'permission_id' => $permission->id,
            ]);

            ProfilePermissionModule::create([
                'profile_id' => $profile->id,
                'module_id' => $module->id,
                'permission_id' => $permission->id,
            ]);
        }

        return User::factory()->create(['profile_id' => $profile->id]);
    }

    /**
     * @return array{0:Profile,1:Module,2:Permission,3:Permission}
     */
    private function targetRoleAndModule(): array
    {
        $role = Profile::create([
            'name' => 'Usuario',
            'slug' => 'usuario',
            'is_active' => true,
        ]);

        $module = Module::create([
            'name' => 'Eventos',
            'slug' => 'eventos',
            'group' => 'Eventos',
            'icon' => 'fa-calendar-days',
            'order' => 50,
            'is_active' => true,
        ]);

        $view = $this->permiso('Ver', 'view');
        $edit = $this->permiso('Modificar', 'edit');

        foreach ([$view, $edit] as $permission) {
            DB::table('module_permission')->insertOrIgnore([
                'module_id' => $module->id,
                'permission_id' => $permission->id,
            ]);
        }

        return [$role, $module, $view, $edit];
    }

    public function test_la_matriz_muestra_roles_y_seleccion(): void
    {
        $admin = $this->adminWithMatriz();

        $this->actingAs($admin)
            ->get('/maestros/matriz')
            ->assertOk()
            ->assertSee('Matriz de perfiles')
            ->assertSee('Asocia o desasocia')
            ->assertSee('Administrador');
    }

    public function test_la_matriz_exige_el_modulo(): void
    {
        $this->matrizModule();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/maestros/matriz')
            ->assertForbidden();
    }

    public function test_datos_devuelve_modulos_permisos_y_concesiones(): void
    {
        $admin = $this->adminWithMatriz();
        [$role, $module, $view, $edit] = $this->targetRoleAndModule();

        ProfilePermissionModule::create([
            'profile_id' => $role->id,
            'module_id' => $module->id,
            'permission_id' => $view->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/matriz/datos?' . http_build_query(['profile_id' => $role->id]))
            ->assertOk()
            ->assertJsonPath('profile.name', 'Usuario')
            ->assertJsonPath('can_grant', true)
            ->assertJsonPath('can_revoke', true);

        $modules = collect($response->json('modules'));
        $target = $modules->firstWhere('id', $module->id);

        $this->assertNotNull($target);
        $this->assertTrue($target['associated']);
        $this->assertTrue(collect($target['permissions'])->firstWhere('id', $view->id)['granted']);
        $this->assertFalse(collect($target['permissions'])->firstWhere('id', $edit->id)['granted']);
    }

    public function test_asociar_modulo_al_rol(): void
    {
        $admin = $this->adminWithMatriz();
        [$role, $module, $view] = $this->targetRoleAndModule();

        $this->actingAs($admin)
            ->postJson('/matriz/asociar', [
                'profile_id' => $role->id,
                'module_id' => $module->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'associated' => true]);

        $this->assertDatabaseHas('profile_permission_module', [
            'profile_id' => $role->id,
            'module_id' => $module->id,
            'permission_id' => $view->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.granted',
            'user_id' => $admin->id,
        ]);
    }

    public function test_desasociar_modulo_del_rol(): void
    {
        $admin = $this->adminWithMatriz();
        [$role, $module, $view, $edit] = $this->targetRoleAndModule();

        ProfilePermissionModule::create([
            'profile_id' => $role->id,
            'module_id' => $module->id,
            'permission_id' => $view->id,
        ]);

        ProfilePermissionModule::create([
            'profile_id' => $role->id,
            'module_id' => $module->id,
            'permission_id' => $edit->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/matriz/desasociar', [
                'profile_id' => $role->id,
                'module_id' => $module->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'associated' => false]);

        $this->assertDatabaseMissing('profile_permission_module', [
            'profile_id' => $role->id,
            'module_id' => $module->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.revoked',
            'user_id' => $admin->id,
        ]);
    }

    public function test_asignar_y_revocar_permiso(): void
    {
        $admin = $this->adminWithMatriz();
        [$role, $module, $view, $edit] = $this->targetRoleAndModule();

        ProfilePermissionModule::create([
            'profile_id' => $role->id,
            'module_id' => $module->id,
            'permission_id' => $view->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/matriz/permiso/asignar', [
                'profile_id' => $role->id,
                'module_id' => $module->id,
                'permission_id' => $edit->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('profile_permission_module', [
            'profile_id' => $role->id,
            'module_id' => $module->id,
            'permission_id' => $edit->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/matriz/permiso/revocar', [
                'profile_id' => $role->id,
                'module_id' => $module->id,
                'permission_id' => $edit->id,
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('profile_permission_module', [
            'profile_id' => $role->id,
            'module_id' => $module->id,
            'permission_id' => $edit->id,
        ]);
    }

    public function test_no_permite_asignar_un_permiso_no_disponible(): void
    {
        $admin = $this->adminWithMatriz();
        [$role, $module] = $this->targetRoleAndModule();

        $delete = $this->permiso('Eliminar', 'delete');

        $this->actingAs($admin)
            ->postJson('/matriz/permiso/asignar', [
                'profile_id' => $role->id,
                'module_id' => $module->id,
                'permission_id' => $delete->id,
            ])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_aplicar_matriz_otorga_y_revoca(): void
    {
        $admin = $this->adminWithMatriz();
        [$role, $module, $view, $edit] = $this->targetRoleAndModule();

        ProfilePermissionModule::create([
            'profile_id' => $role->id,
            'module_id' => $module->id,
            'permission_id' => $view->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/matriz/aplicar', [
                'profile_id' => $role->id,
                'modules' => [[
                    'module_id' => $module->id,
                    'permission_ids' => [$edit->id],
                ]],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('profile_permission_module', [
            'profile_id' => $role->id,
            'module_id' => $module->id,
            'permission_id' => $view->id,
        ]);

        $this->assertDatabaseHas('profile_permission_module', [
            'profile_id' => $role->id,
            'module_id' => $module->id,
            'permission_id' => $edit->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.revoked',
            'user_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access.granted',
            'user_id' => $admin->id,
        ]);
    }

    public function test_aplicar_rechaza_permiso_no_disponible(): void
    {
        $admin = $this->adminWithMatriz();
        [$role, $module] = $this->targetRoleAndModule();

        $delete = $this->permiso('Eliminar', 'delete');

        $this->actingAs($admin)
            ->postJson('/matriz/aplicar', [
                'profile_id' => $role->id,
                'modules' => [[
                    'module_id' => $module->id,
                    'permission_ids' => [$delete->id],
                ]],
            ])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_aplicar_exige_otorgar_y_revocar(): void
    {
        $profile = Profile::create([
            'name' => 'Consulta',
            'slug' => 'consulta',
            'is_active' => true,
        ]);

        $module = $this->matrizModule();
        $view = $this->permiso('Ver', 'view');

        DB::table('module_permission')->insertOrIgnore([
            'module_id' => $module->id,
            'permission_id' => $view->id,
        ]);

        ProfilePermissionModule::create([
            'profile_id' => $profile->id,
            'module_id' => $module->id,
            'permission_id' => $view->id,
        ]);

        $user = User::factory()->create(['profile_id' => $profile->id]);

        [$role, $target] = $this->targetRoleAndModule();

        $this->actingAs($user)
            ->postJson('/matriz/aplicar', [
                'profile_id' => $role->id,
                'modules' => [[
                    'module_id' => $target->id,
                    'permission_ids' => [],
                ]],
            ])
            ->assertForbidden();
    }
}
