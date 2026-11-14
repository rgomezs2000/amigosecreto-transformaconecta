<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $categories = Category::query()->pluck('id', 'name');

        // El código («code») es la identidad fija del módulo: su slug puede
        // cambiar desde el maestro de Módulos y con él cambia la URL pública,
        // pero el código nunca. Al sembrar se busca por código para no
        // duplicar el módulo si el administrador cambió su slug o su nombre.
        $modules = [
            ['code' => 'inicio', 'name' => 'Inicio', 'slug' => '', 'group' => 'General', 'icon' => 'fa-house', 'order' => 1],
            ['code' => 'perfil', 'name' => 'Perfil', 'slug' => 'perfil', 'group' => 'General', 'icon' => 'fa-id-card', 'order' => 5],
            ['code' => 'roles', 'name' => 'Roles', 'slug' => 'roles', 'group' => 'Maestros', 'icon' => 'fa-user-shield', 'order' => 10],
            ['code' => 'permisos', 'name' => 'Permisos', 'slug' => 'permisos', 'group' => 'Maestros', 'icon' => 'fa-key', 'order' => 20],
            ['code' => 'categorias', 'name' => 'Categorías', 'slug' => 'categorias', 'group' => 'Maestros', 'icon' => 'fa-tags', 'order' => 25],
            ['code' => 'modulos', 'name' => 'Módulos', 'slug' => 'modulos', 'group' => 'Maestros', 'icon' => 'fa-cubes', 'order' => 30],
            ['code' => 'matriz', 'name' => 'Matriz de perfiles', 'slug' => 'matriz', 'group' => 'Maestros', 'icon' => 'fa-table-cells', 'order' => 35],
            ['code' => 'usuarios', 'name' => 'Usuarios', 'slug' => 'usuarios', 'group' => 'Usuarios', 'icon' => 'fa-users', 'order' => 40],
            ['code' => 'eventos', 'name' => 'Eventos', 'slug' => 'eventos', 'group' => 'Eventos', 'icon' => 'fa-calendar-days', 'order' => 50],
            ['code' => 'control-notificaciones', 'name' => 'Control de Notificaciones', 'slug' => 'control-notificaciones', 'group' => 'Administración', 'icon' => 'fa-bell-slash', 'order' => 55],
            ['code' => 'moderacion', 'name' => 'Moderación', 'slug' => 'moderacion', 'group' => 'Administración', 'icon' => 'fa-shield-halved', 'order' => 57],
            // Solo el superadministrador: el acceso se concede por usuario en
            // SuperAdminAccessSeeder, nunca al perfil Administrador.
            ['code' => 'restablecer-dinamica', 'name' => 'Restablecer dinámica', 'slug' => 'restablecer-dinamica', 'group' => 'Administración', 'icon' => 'fa-arrows-rotate', 'order' => 58],
            ['code' => 'chat', 'name' => 'Chat', 'slug' => 'chat', 'group' => 'Mensajes', 'icon' => 'fa-comments', 'order' => 56],

            // Participantes: todos cuelgan del grupo Amigo secreto.
            // Este es el orden del panel del usuario: sorteo, ver tu amigo,
            // lista de deseos, intercambio de pistas y gran revelación.
            //
            // Antes de «Lista de deseos» van los módulos de monitoreo de la
            // administración: Participantes y Usuarios asociados (75 y 76).
            ['code' => 'asignar-amigo', 'name' => 'Sorteo ciego', 'slug' => 'asignar-amigo', 'group' => 'Amigo secreto', 'icon' => 'fa-handshake', 'order' => 60],
            ['code' => 'ver-tu-amigo', 'name' => 'Ver tu amigo', 'slug' => 'ver-tu-amigo', 'group' => 'Amigo secreto', 'icon' => 'fa-user-secret', 'order' => 70],
            ['code' => 'participantes', 'name' => 'Participantes', 'slug' => 'participantes', 'group' => 'Amigo secreto', 'icon' => 'fa-users', 'order' => 75],
            ['code' => 'usuarios-asociados', 'name' => 'Usuarios asociados', 'slug' => 'usuarios-asociados', 'group' => 'Amigo secreto', 'icon' => 'fa-link', 'order' => 76],
            ['code' => 'lista-de-deseos', 'name' => 'Lista de deseos', 'slug' => 'lista-de-deseos', 'group' => 'Amigo secreto', 'icon' => 'fa-list', 'order' => 80],
            ['code' => 'intercambio-de-pista', 'name' => 'Intercambio de pista', 'slug' => 'intercambio-de-pista', 'group' => 'Amigo secreto', 'icon' => 'fa-comments', 'order' => 90],
            ['code' => 'gran-revelacion', 'name' => 'Gran revelación', 'slug' => 'gran-revelacion', 'group' => 'Amigo secreto', 'icon' => 'fa-star', 'order' => 100],
            ['code' => 'obsequios', 'name' => 'Obsequios', 'slug' => 'obsequios', 'group' => 'Amigo secreto', 'icon' => 'fa-gift', 'order' => 110],
            ['code' => 'notificaciones', 'name' => 'Notificaciones', 'slug' => 'notificaciones', 'group' => 'Notificaciones', 'icon' => 'fa-bell', 'order' => 100],

            // Centro de ayuda: contenido estático guardado en help_articles.
            // La URL de la guía es /ayuda/guia para no repetir la categoría.
            ['code' => 'acerca-de', 'name' => 'Acerca de', 'slug' => 'acerca-de', 'group' => 'Ayuda', 'icon' => 'fa-circle-info', 'order' => 10],
            ['code' => 'ayuda', 'name' => 'Ayuda', 'slug' => 'guia', 'group' => 'Ayuda', 'icon' => 'fa-book-open', 'order' => 20],
            ['code' => 'faq', 'name' => 'FAQ', 'slug' => 'faq', 'group' => 'Ayuda', 'icon' => 'fa-circle-question', 'order' => 30],
            // Visible desde el menú; su pantalla sigue sin lógica y muestra el
            // marcador de posición hasta que se conecte el controlador.
            ['code' => 'contacto-soporte', 'name' => 'Contacto y soporte', 'slug' => 'contacto-soporte', 'group' => 'Ayuda', 'icon' => 'fa-headset', 'order' => 40],

            // Administradores: reportes y gestión del amigo secreto.
            ['code' => 'usuarios-obsequiados', 'name' => 'Usuarios obsequiados', 'slug' => 'usuarios-obsequiados', 'group' => 'Amigo secreto', 'icon' => 'fa-gift', 'order' => 150],
            // Monitoreo de las pistas que se emiten a cada amigo secreto.
            // Solo la administración; al final de la categoría y sin contenido.
            ['code' => 'pistas', 'name' => 'Pistas', 'slug' => 'pistas', 'group' => 'Amigo secreto', 'icon' => 'fa-lightbulb', 'order' => 160],

            ['code' => 'login', 'name' => 'Login', 'slug' => 'login', 'group' => 'Sesión', 'icon' => 'fa-right-to-bracket', 'order' => 998],
            ['code' => 'logout', 'name' => 'Salir', 'slug' => 'logout', 'group' => 'Sesión', 'icon' => 'fa-right-from-bracket', 'order' => 999],
        ];

        foreach ($modules as $module) {
            Module::query()->firstOrCreate(
                ['code' => $module['code']],
                $module + [
                    'category_id' => $categories[$module['group']] ?? null,
                    'is_active' => true,
                ]
            );
        }
    }
}
