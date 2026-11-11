<?php

use App\Http\Controllers\CategoriasController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\MatrizPerfilesController;
use App\Http\Controllers\ModulosController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PermisosController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

// Punto de entrada: sin sesión manda al login; con sesión muestra el panel.
Route::get('/', [HomeController::class, 'index'])->name('home');

// Pantalla de acceso.
Route::get('/login', [LoginController::class, 'index'])->name('login');

// Validación de usuario/correo y clave (responde JSON para el AJAX).
Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');

// Acceso con Google por OAuth (flujo de redirección).
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'googleLogin'])->name('google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'handleCallback'])->name('google.callback');

// El panel es el mismo index de HomeController.
Route::get('/dashboard', [HomeController::class, 'index'])
    ->middleware('auth')
    ->name('dashboard');

// Cierre de sesión.
Route::post('/logout', [LogoutController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// Perfil propio: el módulo compartido "perfiles".
Route::get('/perfiles', [PerfilController::class, 'index'])
    ->middleware('auth')
    ->name('perfil.index');

Route::post('/perfil', [PerfilController::class, 'cambiarPerfil'])
    ->middleware('auth')
    ->name('perfil.cambiar');

Route::post('/perfil/clave', [PerfilController::class, 'cambiarClave'])
    ->middleware('auth')
    ->name('perfil.clave');

Route::get('/perfil/consultar-login', [UsuarioController::class, 'consultarLoginExiste'])
    ->middleware('auth')
    ->name('perfil.login-existe');

// Roles (maestro de perfiles/roles).
Route::get('/maestros/roles', [RolesController::class, 'index'])
    ->middleware('auth')
    ->name('roles.index');

Route::get('/roles/datos', [RolesController::class, 'datos'])
    ->middleware('auth')
    ->name('roles.datos');

Route::post('/roles', [RolesController::class, 'crear'])
    ->middleware('auth')
    ->name('roles.crear');

Route::post('/roles/eliminar-varios', [RolesController::class, 'eliminarVarios'])
    ->middleware('auth')
    ->name('roles.eliminar-varios');

Route::get('/roles/{role}', [RolesController::class, 'editar'])
    ->middleware('auth')
    ->name('roles.editar');

Route::get('/roles/{role}/ver', [RolesController::class, 'ver'])
    ->middleware('auth')
    ->name('roles.ver');

Route::put('/roles/{role}', [RolesController::class, 'modificar'])
    ->middleware('auth')
    ->name('roles.modificar');

Route::delete('/roles/{role}', [RolesController::class, 'eliminar'])
    ->middleware('auth')
    ->name('roles.eliminar');

// Permisos (catálogo de permisos).
Route::get('/maestros/permisos', [PermisosController::class, 'index'])
    ->middleware('auth')
    ->name('permisos.index');

Route::get('/permisos/datos', [PermisosController::class, 'datos'])
    ->middleware('auth')
    ->name('permisos.datos');

Route::post('/permisos', [PermisosController::class, 'crear'])
    ->middleware('auth')
    ->name('permisos.crear');

Route::post('/permisos/eliminar-varios', [PermisosController::class, 'eliminarVarios'])
    ->middleware('auth')
    ->name('permisos.eliminar-varios');

Route::get('/permisos/{permission}', [PermisosController::class, 'editar'])
    ->middleware('auth')
    ->name('permisos.editar');

Route::get('/permisos/{permission}/ver', [PermisosController::class, 'ver'])
    ->middleware('auth')
    ->name('permisos.ver');

Route::put('/permisos/{permission}', [PermisosController::class, 'modificar'])
    ->middleware('auth')
    ->name('permisos.modificar');

Route::delete('/permisos/{permission}', [PermisosController::class, 'eliminar'])
    ->middleware('auth')
    ->name('permisos.eliminar');

// Categorías (clasificación de los módulos).
Route::get('/maestros/categorias', [CategoriasController::class, 'index'])
    ->middleware('auth')
    ->name('categorias.index');

Route::get('/categorias/datos', [CategoriasController::class, 'datos'])
    ->middleware('auth')
    ->name('categorias.datos');

Route::post('/categorias', [CategoriasController::class, 'crear'])
    ->middleware('auth')
    ->name('categorias.crear');

Route::post('/categorias/eliminar-varios', [CategoriasController::class, 'eliminarVarios'])
    ->middleware('auth')
    ->name('categorias.eliminar-varios');

Route::get('/categorias/{category}', [CategoriasController::class, 'editar'])
    ->middleware('auth')
    ->name('categorias.editar');

Route::get('/categorias/{category}/ver', [CategoriasController::class, 'ver'])
    ->middleware('auth')
    ->name('categorias.ver');

Route::put('/categorias/{category}', [CategoriasController::class, 'modificar'])
    ->middleware('auth')
    ->name('categorias.modificar');

Route::delete('/categorias/{category}', [CategoriasController::class, 'eliminar'])
    ->middleware('auth')
    ->name('categorias.eliminar');

// Módulos (catálogo de módulos y sus rutas).
Route::get('/maestros/modulos', [ModulosController::class, 'index'])
    ->middleware('auth')
    ->name('modulos.index');

Route::get('/modulos/datos', [ModulosController::class, 'datos'])
    ->middleware('auth')
    ->name('modulos.datos');

Route::post('/modulos', [ModulosController::class, 'crear'])
    ->middleware('auth')
    ->name('modulos.crear');

Route::post('/modulos/eliminar-varios', [ModulosController::class, 'eliminarVarios'])
    ->middleware('auth')
    ->name('modulos.eliminar-varios');

Route::get('/modulos/{module:id}', [ModulosController::class, 'editar'])
    ->middleware('auth')
    ->name('modulos.editar');

Route::get('/modulos/{module:id}/ver', [ModulosController::class, 'ver'])
    ->middleware('auth')
    ->name('modulos.ver');

Route::put('/modulos/{module:id}', [ModulosController::class, 'modificar'])
    ->middleware('auth')
    ->name('modulos.modificar');

Route::delete('/modulos/{module:id}', [ModulosController::class, 'eliminar'])
    ->middleware('auth')
    ->name('modulos.eliminar');

// Matriz de perfiles (roles vs módulos y permisos).
Route::get('/maestros/matriz', [MatrizPerfilesController::class, 'index'])
    ->middleware('auth')
    ->name('matriz.index');

Route::get('/matriz/datos', [MatrizPerfilesController::class, 'datos'])
    ->middleware('auth')
    ->name('matriz.datos');

Route::post('/matriz/asociar', [MatrizPerfilesController::class, 'asociarModulo'])
    ->middleware('auth')
    ->name('matriz.asociar');

Route::post('/matriz/desasociar', [MatrizPerfilesController::class, 'desasociarModulo'])
    ->middleware('auth')
    ->name('matriz.desasociar');

Route::post('/matriz/permiso/asignar', [MatrizPerfilesController::class, 'asignarPermiso'])
    ->middleware('auth')
    ->name('matriz.asignar');

Route::post('/matriz/permiso/revocar', [MatrizPerfilesController::class, 'revocarPermiso'])
    ->middleware('auth')
    ->name('matriz.revocar');

Route::post('/matriz/aplicar', [MatrizPerfilesController::class, 'aplicar'])
    ->middleware('auth')
    ->name('matriz.aplicar');

// Página de un módulo: /[categoria]/[modulo].
Route::get('/{category:slug}/{module:slug}', [ModuleController::class, 'show'])
    ->middleware(['auth', 'module.access'])
    ->name('modules.show');

// Cualquier ruta no registrada responde 404 (y usa el shell si hay sesión).
Route::fallback(function () {
    abort(404);
});
