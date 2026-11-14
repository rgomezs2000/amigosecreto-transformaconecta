<?php

namespace App\Support;

use App\Http\Controllers\AyudaController;
use App\Http\Controllers\CategoriasController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DinamicaController;
use App\Http\Controllers\EventosController;
use App\Http\Controllers\MatrizPerfilesController;
use App\Http\Controllers\ModeracionController;
use App\Http\Controllers\ModulosController;
use App\Http\Controllers\NotificacionesController;
use App\Http\Controllers\ParticipantesController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PermisosController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\SorteoCiegoController;
use App\Http\Controllers\UsuariosAsociadosController;
use App\Http\Controllers\UsuariosController;
use App\Http\Controllers\VerTuAmigoController;
use App\Models\Module;

/**
 * Relación entre los módulos del sistema y las pantallas ya construidas.
 *
 * Las claves son el código interno del módulo (modules.code), que no cambia
 * aunque se renombre el slug o el nombre en el maestro de Módulos. Un módulo
 * sin entrada aquí sigue existiendo como módulo, pero su página es el
 * marcador de posición («en construcción»).
 */
final class ModulePages
{
    /**
     * @var array<string, array{controller: class-string, action: string, superadmin?: bool}>
     */
    private const PAGES = [
        'perfil' => ['controller' => PerfilController::class, 'action' => 'index'],
        'roles' => ['controller' => RolesController::class, 'action' => 'index'],
        'permisos' => ['controller' => PermisosController::class, 'action' => 'index'],
        'categorias' => ['controller' => CategoriasController::class, 'action' => 'index'],
        'modulos' => ['controller' => ModulosController::class, 'action' => 'index'],
        'matriz' => ['controller' => MatrizPerfilesController::class, 'action' => 'index'],
        'usuarios' => ['controller' => UsuariosController::class, 'action' => 'index'],
        'eventos' => ['controller' => EventosController::class, 'action' => 'index'],
        'chat' => ['controller' => ChatController::class, 'action' => 'index'],
        'control-notificaciones' => ['controller' => NotificacionesController::class, 'action' => 'index'],
        'moderacion' => ['controller' => ModeracionController::class, 'action' => 'index'],
        'restablecer-dinamica' => [
            'controller' => DinamicaController::class,
            'action' => 'index',
            'superadmin' => true,
        ],
        'asignar-amigo' => ['controller' => SorteoCiegoController::class, 'action' => 'index'],
        'ver-tu-amigo' => ['controller' => VerTuAmigoController::class, 'action' => 'index'],
        'participantes' => ['controller' => ParticipantesController::class, 'action' => 'index'],
        'usuarios-asociados' => ['controller' => UsuariosAsociadosController::class, 'action' => 'index'],
        // Centro de ayuda: las tres pantallas son estáticas y leen la base.
        'acerca-de' => ['controller' => AyudaController::class, 'action' => 'acercaDe'],
        'ayuda' => ['controller' => AyudaController::class, 'action' => 'guia'],
        'faq' => ['controller' => AyudaController::class, 'action' => 'faq'],
    ];

    /**
     * Código canónico de la pantalla asociada a un módulo.
     */
    public function codeFor(Module $module): ?string
    {
        foreach ([$module->code, $module->slug] as $candidate) {
            $code = Module::canonicalCode($candidate);

            if ($code !== null && isset(self::PAGES[$code])) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Pantalla que atiende al módulo, o null si todavía no tiene una.
     *
     * @return array{controller: class-string, action: string, superadmin?: bool}|null
     */
    public function handlerFor(Module $module): ?array
    {
        $code = $this->codeFor($module);

        return $code !== null ? self::PAGES[$code] : null;
    }
}
