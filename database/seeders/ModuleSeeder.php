<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            ['name' => 'Perfiles', 'slug' => 'maestros.perfiles', 'group' => 'Maestros', 'icon' => 'fa-id-card', 'order' => 10],
            ['name' => 'Permisos', 'slug' => 'maestros.permisos', 'group' => 'Maestros', 'icon' => 'fa-key', 'order' => 20],
            ['name' => 'Módulos', 'slug' => 'maestros.modulos', 'group' => 'Maestros', 'icon' => 'fa-cubes', 'order' => 30],
            ['name' => 'Usuarios', 'slug' => 'usuarios', 'group' => 'Usuarios', 'icon' => 'fa-users', 'order' => 40],
            ['name' => 'Eventos', 'slug' => 'eventos', 'group' => 'Eventos', 'icon' => 'fa-calendar-days', 'order' => 50],

            // Participantes: todos cuelgan del grupo Amigo secreto.
            ['name' => 'Asignar amigo', 'slug' => 'amigos.asignar', 'group' => 'Amigo secreto', 'icon' => 'fa-handshake', 'order' => 60],
            ['name' => 'Ver tu amigo', 'slug' => 'amigos.ver', 'group' => 'Amigo secreto', 'icon' => 'fa-user-secret', 'order' => 70],
            ['name' => 'Obsequios', 'slug' => 'amigos.obsequios', 'group' => 'Amigo secreto', 'icon' => 'fa-gift', 'order' => 80],
            ['name' => 'Lista de deseos', 'slug' => 'amigos.deseos', 'group' => 'Amigo secreto', 'icon' => 'fa-list', 'order' => 90],
            ['name' => 'Intercambio de pista', 'slug' => 'amigos.intercambio', 'group' => 'Amigo secreto', 'icon' => 'fa-comments', 'order' => 95],
            ['name' => 'Revelación', 'slug' => 'amigos.revelacion', 'group' => 'Amigo secreto', 'icon' => 'fa-star', 'order' => 96],
            ['name' => 'Notificaciones', 'slug' => 'amigos.notificaciones', 'group' => 'Notificaciones', 'icon' => 'fa-bell', 'order' => 100],

            // Administradores: reportes y gestión del amigo secreto.
            ['name' => 'Participantes', 'slug' => 'amigo.participantes', 'group' => 'Amigo secreto', 'icon' => 'fa-users', 'order' => 110],
            ['name' => 'Usuarios asociados', 'slug' => 'amigo.asociado', 'group' => 'Amigo secreto', 'icon' => 'fa-link', 'order' => 120],
            ['name' => 'Lista de deseos', 'slug' => 'amigo.deseos', 'group' => 'Amigo secreto', 'icon' => 'fa-list', 'order' => 130],
            ['name' => 'Usuarios obsequiados', 'slug' => 'amigo.obsequiados', 'group' => 'Amigo secreto', 'icon' => 'fa-gift', 'order' => 140],

            ['name' => 'Salir', 'slug' => 'salir', 'group' => 'Sesión', 'icon' => 'fa-right-from-bracket', 'order' => 999],
        ];

        foreach ($modules as $module) {
            Module::updateOrCreate(
                ['slug' => $module['slug']],
                $module + ['is_active' => true],
            );
        }
    }
}
