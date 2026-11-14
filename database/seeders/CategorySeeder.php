<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'General', 'slug' => null, 'sort_order' => 1],
            ['name' => 'Maestros', 'slug' => 'maestros', 'sort_order' => 2],
            ['name' => 'Usuarios', 'slug' => 'usuarios', 'sort_order' => 3],
            ['name' => 'Eventos', 'slug' => 'eventos', 'sort_order' => 4],
            ['name' => 'Amigo secreto', 'slug' => 'amigo-secreto', 'sort_order' => 5],
            ['name' => 'Administración', 'slug' => 'administracion', 'sort_order' => 6],
            ['name' => 'Mensajes', 'slug' => 'mensajes', 'sort_order' => 7],
            // Ayuda va antes de Notificaciones y Sesión en el menú.
            ['name' => 'Ayuda', 'slug' => 'ayuda', 'sort_order' => 8],
            ['name' => 'Notificaciones', 'slug' => 'notificaciones', 'sort_order' => 9],
            ['name' => 'Sesión', 'slug' => null, 'sort_order' => 10],
        ];

        foreach ($categories as $category) {
            Category::query()->firstOrCreate(
                ['name' => $category['name']],
                [
                    'slug' => $category['slug'],
                    'sort_order' => $category['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }
}
