<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Solo primera instalación: si ya hay permisos, no se tocan.
        if (Permission::query()->exists()) {
            return;
        }

        $permissions = [
            ['name' => 'Ver', 'slug' => 'view'],
            ['name' => 'Crear', 'slug' => 'create'],
            ['name' => 'Modificar', 'slug' => 'edit'],
            ['name' => 'Eliminar', 'slug' => 'delete'],
            ['name' => 'Otorgar', 'slug' => 'grant'],
            ['name' => 'Revocar', 'slug' => 'revoke'],
            ['name' => 'Desbloquear', 'slug' => 'unlock'],
            ['name' => 'Bloquear', 'slug' => 'block'],
            ['name' => 'Abrir', 'slug' => 'open'],
            ['name' => 'Posponer', 'slug' => 'postpone'],
            ['name' => 'Cancelar', 'slug' => 'cancel'],
            ['name' => 'Suspender', 'slug' => 'suspend'],
            ['name' => 'Resetear', 'slug' => 'reset'],
            ['name' => 'Resetear sorteo', 'slug' => 'reset_draw'],
            ['name' => 'Asignar', 'slug' => 'assign'],
            ['name' => 'Cargar obsequios', 'slug' => 'gifts'],
            ['name' => 'Ver lista de deseos', 'slug' => 'wishlist'],
            ['name' => 'Intercambiar pistas', 'slug' => 'clues'],
            ['name' => 'Revelar', 'slug' => 'reveal'],
            ['name' => 'Notificaciones', 'slug' => 'notifications'],
        ];

        foreach ($permissions as $permission) {
            Permission::create($permission);
        }
    }
}
