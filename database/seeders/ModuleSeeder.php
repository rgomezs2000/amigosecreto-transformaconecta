<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        // Solo primera instalación: si ya hay módulos, no se tocan.
        if (Module::query()->exists()) {
            return;
        }

        $categories = Category::query()->pluck('id', 'name');

        $modules = [
            ['name' => 'Inicio', 'slug' => '', 'group' => 'General', 'icon' => 'fa-house', 'order' => 1],
            ['name' => 'Perfiles', 'slug' => 'perfiles', 'group' => 'General', 'icon' => 'fa-id-card', 'order' => 5],
            ['name' => 'Roles', 'slug' => 'roles', 'group' => 'Maestros', 'icon' => 'fa-user-shield', 'order' => 10],
            ['name' => 'Permisos', 'slug' => 'permisos', 'group' => 'Maestros', 'icon' => 'fa-key', 'order' => 20],
            ['name' => 'Categorías', 'slug' => 'categorias', 'group' => 'Maestros', 'icon' => 'fa-tags', 'order' => 25],
            ['name' => 'Módulos', 'slug' => 'modulos', 'group' => 'Maestros', 'icon' => 'fa-cubes', 'order' => 30],
            ['name' => 'Matriz de perfiles', 'slug' => 'matriz', 'group' => 'Maestros', 'icon' => 'fa-table-cells', 'order' => 35],
            ['name' => 'Usuarios', 'slug' => 'usuarios', 'group' => 'Usuarios', 'icon' => 'fa-users', 'order' => 40],
            ['name' => 'Eventos', 'slug' => 'eventos', 'group' => 'Eventos', 'icon' => 'fa-calendar-days', 'order' => 50],

            // Participantes: todos cuelgan del grupo Amigo secreto.
            ['name' => 'Asignar amigo', 'slug' => 'asignar-amigo', 'group' => 'Amigo secreto', 'icon' => 'fa-handshake', 'order' => 60],
            ['name' => 'Ver tu amigo', 'slug' => 'ver-tu-amigo', 'group' => 'Amigo secreto', 'icon' => 'fa-user-secret', 'order' => 70],
            ['name' => 'Obsequios', 'slug' => 'obsequios', 'group' => 'Amigo secreto', 'icon' => 'fa-gift', 'order' => 80],
            ['name' => 'Lista de deseos', 'slug' => 'lista-de-deseos', 'group' => 'Amigo secreto', 'icon' => 'fa-list', 'order' => 90],
            ['name' => 'Intercambio de pista', 'slug' => 'intercambio-de-pista', 'group' => 'Amigo secreto', 'icon' => 'fa-comments', 'order' => 95],
            ['name' => 'Revelación', 'slug' => 'revelacion', 'group' => 'Amigo secreto', 'icon' => 'fa-star', 'order' => 96],
            ['name' => 'Notificaciones', 'slug' => 'notificaciones', 'group' => 'Notificaciones', 'icon' => 'fa-bell', 'order' => 100],

            // Administradores: reportes y gestión del amigo secreto.
            ['name' => 'Participantes', 'slug' => 'participantes', 'group' => 'Amigo secreto', 'icon' => 'fa-users', 'order' => 110],
            ['name' => 'Usuarios asociados', 'slug' => 'usuarios-asociados', 'group' => 'Amigo secreto', 'icon' => 'fa-link', 'order' => 120],
            ['name' => 'Usuarios obsequiados', 'slug' => 'usuarios-obsequiados', 'group' => 'Amigo secreto', 'icon' => 'fa-gift', 'order' => 140],

            ['name' => 'Login', 'slug' => 'login', 'group' => 'Sesión', 'icon' => 'fa-right-to-bracket', 'order' => 998],
            ['name' => 'Salir', 'slug' => 'logout', 'group' => 'Sesión', 'icon' => 'fa-right-from-bracket', 'order' => 999],
        ];

        foreach ($modules as $module) {
            Module::create($module + [
                'category_id' => $categories[$module['group']] ?? null,
                'is_active' => true,
            ]);
        }
    }
}
