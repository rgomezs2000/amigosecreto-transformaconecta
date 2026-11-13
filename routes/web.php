<?php

use App\Http\Controllers\CategoriasController;
use App\Http\Controllers\AmigoSecretoController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DinamicaController;
use App\Http\Controllers\EventoReinicioController;
use App\Http\Controllers\EventosController;
use App\Http\Controllers\GroupModerationController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\MatrizPerfilesController;
use App\Http\Controllers\ModeracionBloqueosController;
use App\Http\Controllers\ModeracionController;
use App\Http\Controllers\ModeracionGruposController;
use App\Http\Controllers\ModulePageController;
use App\Http\Controllers\ModulosController;
use App\Http\Controllers\NotificacionesController;
use App\Http\Controllers\ObsequiosAmigoController;
use App\Http\Controllers\ParticipantesController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PermisosController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\SorteoCiegoController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\UsuariosAsociadosController;
use App\Http\Controllers\UsuariosController;
use App\Http\Controllers\VerTuAmigoController;
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

// Perfil propio: la página la sirve el enrutador de módulos del final de este
// archivo, con la ruta que tenga el módulo «perfil» en el maestro de Módulos.

Route::post('/perfil', [PerfilController::class, 'cambiarPerfil'])
    ->middleware('auth')
    ->name('perfil.cambiar');

Route::post('/perfil/clave', [PerfilController::class, 'cambiarClave'])
    ->middleware('auth')
    ->name('perfil.clave');

Route::get('/perfil/consultar-login', [UsuarioController::class, 'consultarLoginExiste'])
    ->middleware('auth')
    ->name('perfil.login-existe');

// Matriz del perfil: módulos y permisos del usuario en formato DataTable.
Route::get('/perfil/matriz/datos', [PerfilController::class, 'matrizDatos'])
    ->middleware('auth')
    ->name('perfil.matriz.datos');

// Control de Notificaciones: la página la sirve el enrutador de módulos; aquí
// quedan sus acciones.

Route::post('/administracion/notificaciones', [NotificacionesController::class, 'guardar'])
    ->middleware('auth')
    ->name('notificaciones.guardar');

Route::get('/administracion/notificaciones/contadores', [NotificacionesController::class, 'contadores'])
    ->middleware('auth')
    ->name('notificaciones.contadores');

Route::post('/administracion/notificaciones/usuarios/{user}', [NotificacionesController::class, 'preferenciasUsuario'])
    ->middleware('auth')
    ->name('notificaciones.usuarios.preferences');

// Campana personal: contador, lectura y preferencias de cada usuario.
Route::get('/notificaciones/contador', [NotificacionesController::class, 'contador'])
    ->middleware('auth')
    ->name('notificaciones.contador');

Route::get('/notificaciones/lista', [NotificacionesController::class, 'lista'])
    ->middleware('auth')
    ->name('notificaciones.lista');

Route::post('/notificaciones/leer-todas', [NotificacionesController::class, 'leerTodas'])
    ->middleware('auth')
    ->name('notificaciones.leer-todas');

Route::post('/notificaciones/preferencias', [NotificacionesController::class, 'preferencias'])
    ->middleware('auth')
    ->name('notificaciones.preferencias');

Route::post('/notificaciones/{notification}/leer', [NotificacionesController::class, 'leer'])
    ->middleware('auth')
    ->name('notificaciones.leer');

// Restablecer la dinámica: proceso batch que deja el sistema en cero.
// La página la sirve el enrutador de módulos, solo para el superadministrador;
// aquí quedan las acciones del proceso.

Route::post('/administracion/restablecer-dinamica', [DinamicaController::class, 'iniciar'])
    ->middleware(['auth', 'superadmin'])
    ->name('dinamica.iniciar');

Route::get('/administracion/restablecer-dinamica/progreso', [DinamicaController::class, 'progreso'])
    ->middleware(['auth', 'superadmin'])
    ->name('dinamica.progreso');

// Moderación: pestaña de reportes (solo lectura, con acceso directo al chat),
// pestaña de bloqueos entre usuarios y pestaña de vetados de grupo.
// La página la sirve el enrutador de módulos; aquí quedan sus acciones.

Route::get('/moderacion/datos', [ModeracionController::class, 'datos'])
    ->middleware('auth')
    ->name('moderacion.datos');

Route::get('/moderacion/contadores', [ModeracionController::class, 'contadores'])
    ->middleware('auth')
    ->name('moderacion.contadores');

Route::post('/moderacion/reportes/{report}/tomar', [ModeracionController::class, 'tomar'])
    ->middleware('auth')
    ->name('moderacion.reportes.tomar');

Route::post('/moderacion/reportes/{report}/cerrar', [ModeracionController::class, 'cerrar'])
    ->middleware('auth')
    ->name('moderacion.reportes.cerrar');

// Bloqueos entre usuarios: listado con estado, detalle y sincronización.
Route::get('/moderacion/bloqueos/usuarios', [ModeracionBloqueosController::class, 'usuarios'])
    ->middleware('auth')
    ->name('moderacion.bloqueos.usuarios');

Route::get('/moderacion/bloqueos/{user}', [ModeracionBloqueosController::class, 'detalle'])
    ->middleware('auth')
    ->name('moderacion.bloqueos.detalle');

Route::post('/moderacion/bloqueos/{user}', [ModeracionBloqueosController::class, 'sincronizar'])
    ->middleware('auth')
    ->name('moderacion.bloqueos.sincronizar');

// Vetados de grupo: listado de grupos, detalle y sincronización por grupo.
Route::get('/moderacion/grupos', [ModeracionGruposController::class, 'grupos'])
    ->middleware('auth')
    ->name('moderacion.grupos');

Route::get('/moderacion/grupos/{conversation}/vetados', [ModeracionGruposController::class, 'detalle'])
    ->middleware('auth')
    ->name('moderacion.grupos.vetados');

Route::post('/moderacion/grupos/{conversation}/vetados', [ModeracionGruposController::class, 'sincronizar'])
    ->middleware('auth')
    ->name('moderacion.grupos.vetados.sincronizar');

// Chat (buzón universal, flotante). La página la sirve el enrutador de
// módulos; aquí quedan sus acciones.

Route::get('/chat/resumen', [ChatController::class, 'resumen'])
    ->middleware('auth')
    ->name('chat.resumen');

// Contador liviano del botón flotante: nuevo propio y nuevo de moderación.
Route::get('/chat/contador', [ChatController::class, 'contador'])
    ->middleware('auth')
    ->name('chat.contador');

Route::post('/chat/abrir', [ChatController::class, 'abrir'])
    ->middleware('auth')
    ->name('chat.abrir');

Route::post('/chat/grupo', [ChatController::class, 'grupo'])
    ->middleware('auth')
    ->name('chat.grupo');

Route::get('/chat/{conversation}/mensajes', [ChatController::class, 'mensajes'])
    ->middleware('auth')
    ->name('chat.mensajes');

Route::post('/chat/{conversation}/mensajes', [ChatController::class, 'enviar'])
    ->middleware('auth')
    ->name('chat.enviar');

Route::post('/chat/{conversation}/leer', [ChatController::class, 'leer'])
    ->middleware('auth')
    ->name('chat.leer');

Route::post('/chat/{conversation}/silenciar', [ChatController::class, 'silenciar'])
    ->middleware('auth')
    ->name('chat.silenciar');

Route::post('/chat/{conversation}/limpiar', [ChatController::class, 'limpiar'])
    ->middleware('auth')
    ->name('chat.limpiar');

// Menú de la conversación: vaciar, eliminar, archivar, favorito, fijar y lectura.
Route::post('/chat/{conversation}/vaciar', [ChatController::class, 'vaciar'])
    ->middleware('auth')
    ->name('chat.vaciar');

Route::post('/chat/{conversation}/eliminar', [ChatController::class, 'eliminarPropia'])
    ->middleware('auth')
    ->name('chat.eliminar-propia');

Route::post('/chat/{conversation}/archivar', [ChatController::class, 'archivar'])
    ->middleware('auth')
    ->name('chat.archivar');

Route::delete('/chat/{conversation}/archivar', [ChatController::class, 'desarchivar'])
    ->middleware('auth')
    ->name('chat.desarchivar');

Route::post('/chat/{conversation}/favorito', [ChatController::class, 'favorito'])
    ->middleware('auth')
    ->name('chat.favorito');

Route::post('/chat/{conversation}/fijar', [ChatController::class, 'fijar'])
    ->middleware('auth')
    ->name('chat.fijar');

Route::post('/chat/{conversation}/no-leido', [ChatController::class, 'noLeido'])
    ->middleware('auth')
    ->name('chat.no-leido');

// Mensaje fijado que se muestra en el cintillo del chat.
Route::post('/chat/{conversation}/fijar-mensaje/{message}', [ChatController::class, 'fijarMensaje'])
    ->middleware('auth')
    ->name('chat.mensaje.fijar');

Route::delete('/chat/{conversation}/mensaje-fijado', [ChatController::class, 'desfijarMensaje'])
    ->middleware('auth')
    ->name('chat.mensaje.desfijar');

// Acciones por persona desde la pestaña Usuarios.
Route::post('/chat/usuarios/{user}/silenciar', [ChatController::class, 'silenciarUsuario'])
    ->middleware('auth')
    ->name('chat.usuarios.silenciar');

Route::post('/chat/usuarios/{user}/reportar', [ChatController::class, 'reportarUsuario'])
    ->middleware('auth')
    ->name('chat.usuarios.reportar');

Route::post('/chat/usuarios/{user}/bloquear', [ChatController::class, 'bloquear'])
    ->middleware('auth')
    ->name('chat.bloquear');

Route::delete('/chat/usuarios/{user}/bloquear', [ChatController::class, 'desbloquear'])
    ->middleware('auth')
    ->name('chat.desbloquear');

Route::post('/chat/{conversation}/salir', [ChatController::class, 'salir'])
    ->middleware('auth')
    ->name('chat.salir');

Route::post('/chat/{conversation}/reportar-conversacion', [ChatController::class, 'reportarConversacion'])
    ->middleware('auth')
    ->name('chat.reportar-conversacion');

Route::post('/chat/{conversation}/expulsar/{user}', [ChatController::class, 'expulsar'])
    ->middleware('auth')
    ->name('chat.expulsar');

Route::delete('/chat/{conversation}', [ChatController::class, 'eliminarConversacion'])
    ->middleware('auth')
    ->name('chat.eliminar');

// Invitación a un grupo: sin sesión manda al acceso; con sesión, al panel,
// donde el chat muestra el modal de la invitación.
Route::get('/chat/grupo/invitacion/{token}', [GroupModerationController::class, 'verInvitacion'])
    ->name('chat.grupo.invitacion');

Route::get('/chat/grupo/invitacion/{token}/datos', [GroupModerationController::class, 'datosInvitacion'])
    ->middleware('auth')
    ->name('chat.grupo.invitacion.datos');

Route::post('/chat/grupo/invitacion/{token}/aceptar', [GroupModerationController::class, 'aceptar'])
    ->middleware('auth')
    ->name('chat.grupo.aceptar');

Route::post('/chat/grupo/invitacion/{token}/rechazar', [GroupModerationController::class, 'rechazar'])
    ->middleware('auth')
    ->name('chat.grupo.rechazar');

Route::post('/chat/grupo/{conversation}/invitar', [GroupModerationController::class, 'invitar'])
    ->middleware('auth')
    ->name('chat.grupo.invitar');

Route::post('/chat/grupo/{conversation}/suspender/{user}', [GroupModerationController::class, 'suspender'])
    ->middleware('auth')
    ->name('chat.grupo.suspender');

Route::post('/chat/grupo/{conversation}/banear/{user}', [GroupModerationController::class, 'banear'])
    ->middleware('auth')
    ->name('chat.grupo.banear');

Route::delete('/chat/grupo/{conversation}/banear/{user}', [GroupModerationController::class, 'desbanear'])
    ->middleware('auth')
    ->name('chat.grupo.desbanear');

Route::get('/chat/grupo/{conversation}/baneados', [GroupModerationController::class, 'baneados'])
    ->middleware('auth')
    ->name('chat.grupo.baneados');

Route::get('/chat/grupo/{conversation}/miembros', [ChatController::class, 'miembros'])
    ->middleware('auth')
    ->name('chat.grupo.miembros');

Route::post('/chat/{conversation}/moderar', [ChatController::class, 'moderar'])
    ->middleware('auth')
    ->name('chat.moderar');

Route::post('/chat/mensajes/{message}/editar', [ChatController::class, 'editar'])
    ->middleware('auth')
    ->name('chat.editar');

Route::post('/chat/mensajes/{message}/eliminar', [ChatController::class, 'eliminar'])
    ->middleware('auth')
    ->name('chat.eliminar');

// Elimina un adjunto concreto; si era el último del mensaje, se borra también
// el mensaje para no dejar una burbuja vacía.
Route::post('/chat/adjuntos/{attachment}/eliminar', [ChatController::class, 'eliminarAdjunto'])
    ->middleware('auth')
    ->name('chat.adjuntos.eliminar');

Route::post('/chat/mensajes/{message}/reaccionar', [ChatController::class, 'reaccionar'])
    ->middleware('auth')
    ->name('chat.reaccionar');

Route::post('/chat/mensajes/{message}/reportar', [ChatController::class, 'reportar'])
    ->middleware('auth')
    ->name('chat.reportar');

Route::get('/chat/amigo-secreto', [AmigoSecretoController::class, 'index'])
    ->middleware('auth')
    ->name('chat.amigo-secreto');

Route::post('/chat/amigo-secreto/{assignment}/abrir', [AmigoSecretoController::class, 'abrir'])
    ->middleware('auth')
    ->name('chat.amigo-secreto.abrir');

Route::get('/chat/enlace', [ChatController::class, 'enlace'])
    ->middleware('auth')
    ->name('chat.enlace');

Route::get('/chat/{conversation}/media', [ChatController::class, 'media'])
    ->middleware('auth')
    ->name('chat.media');

Route::get('/chat/{conversation}/perfil', [ChatController::class, 'perfil'])
    ->middleware('auth')
    ->name('chat.perfil');

// Sorteo ciego del participante: descubrir, volver a ver y avanzar.
// La página la sirve el enrutador de módulos; aquí quedan sus acciones.

Route::post('/amigo-secreto/asignar-amigo/descubrir', [SorteoCiegoController::class, 'descubrir'])
    ->middleware('auth')
    ->name('sorteo.descubrir');

Route::get('/amigo-secreto/asignar-amigo/amigo', [SorteoCiegoController::class, 'ver'])
    ->middleware('auth')
    ->name('sorteo.ver');

// Ver tu amigo: consulta libre del amigo secreto y sus pistas.
// La página la sirve el enrutador de módulos; aquí queda la consulta de datos.

Route::get('/amigo-secreto/ver-tu-amigo/datos', [VerTuAmigoController::class, 'datos'])
    ->middleware('auth')
    ->name('ver-tu-amigo.datos');

// Obsequios de «Ver tu amigo»: regalo único y consumibles del participante.
// Se habilitan cuando el sorteo concluyó y el evento avanzó a esa etapa.

Route::get('/amigo-secreto/ver-tu-amigo/tasa', [ObsequiosAmigoController::class, 'tasa'])
    ->middleware('auth')
    ->name('ver-tu-amigo.tasa');

Route::post('/amigo-secreto/ver-tu-amigo/obsequio', [ObsequiosAmigoController::class, 'guardar'])
    ->middleware('auth')
    ->name('ver-tu-amigo.obsequio.guardar');

Route::put('/amigo-secreto/ver-tu-amigo/obsequio', [ObsequiosAmigoController::class, 'modificar'])
    ->middleware('auth')
    ->name('ver-tu-amigo.obsequio.modificar');

Route::get('/amigo-secreto/ver-tu-amigo/consumibles/datos', [ObsequiosAmigoController::class, 'consumibles'])
    ->middleware('auth')
    ->name('ver-tu-amigo.consumibles.datos');

Route::post('/amigo-secreto/ver-tu-amigo/consumibles', [ObsequiosAmigoController::class, 'crearConsumible'])
    ->middleware('auth')
    ->name('ver-tu-amigo.consumibles.crear');

Route::get('/amigo-secreto/ver-tu-amigo/consumibles/{consumable}', [ObsequiosAmigoController::class, 'verConsumible'])
    ->middleware('auth')
    ->whereNumber('consumable')
    ->name('ver-tu-amigo.consumibles.ver');

Route::put('/amigo-secreto/ver-tu-amigo/consumibles/{consumable}', [ObsequiosAmigoController::class, 'modificarConsumible'])
    ->middleware('auth')
    ->whereNumber('consumable')
    ->name('ver-tu-amigo.consumibles.modificar');

Route::delete('/amigo-secreto/ver-tu-amigo/consumibles/{consumable}', [ObsequiosAmigoController::class, 'eliminarConsumible'])
    ->middleware('auth')
    ->whereNumber('consumable')
    ->name('ver-tu-amigo.consumibles.eliminar');

// Eventos (tarjetas, planificación y ciclo de vida). La página la sirve el
// enrutador de módulos; aquí quedan sus acciones.

Route::get('/eventos/estado', [EventosController::class, 'estado'])
    ->middleware('auth')
    ->name('eventos.estado');

Route::post('/eventos', [EventosController::class, 'crear'])
    ->middleware('auth')
    ->name('eventos.crear');

Route::get('/eventos/{event}/participantes', [EventosController::class, 'participantes'])
    ->middleware('auth')
    ->name('eventos.participantes');

Route::post('/eventos/{event}/iniciar', [EventosController::class, 'iniciar'])
    ->middleware('auth')
    ->name('eventos.iniciar');

Route::post('/eventos/{event}/suspender', [EventosController::class, 'suspender'])
    ->middleware('auth')
    ->name('eventos.suspender');

Route::post('/eventos/{event}/posponer', [EventosController::class, 'posponer'])
    ->middleware('auth')
    ->name('eventos.posponer');

Route::post('/eventos/reiniciar', [EventoReinicioController::class, 'reiniciar'])
    ->middleware('auth')
    ->name('eventos.reiniciar');

Route::post('/eventos/{event}/cancelar', [EventosController::class, 'cancelar'])
    ->middleware('auth')
    ->name('eventos.cancelar');

// Reiniciar el sorteo del evento (solo administración, queda en auditoría).
Route::post('/eventos/{event}/sorteo/reiniciar', [EventosController::class, 'reiniciarSorteo'])
    ->middleware('auth')
    ->name('eventos.sorteo.reiniciar');

// Usuarios (gestión de cuentas). La página la sirve el enrutador de módulos;
// aquí quedan sus acciones.

Route::get('/usuarios/datos', [UsuariosController::class, 'datos'])
    ->middleware('auth')
    ->name('usuarios.datos');

Route::post('/usuarios', [UsuariosController::class, 'crear'])
    ->middleware('auth')
    ->name('usuarios.crear');

Route::post('/usuarios/eliminar-varios', [UsuariosController::class, 'eliminarVarios'])
    ->middleware('auth')
    ->name('usuarios.eliminar-varios');

Route::post('/usuarios/{user}/restablecer', [UsuariosController::class, 'restablecer'])
    ->middleware('auth')
    ->name('usuarios.restablecer');

Route::post('/usuarios/{user}/bloquear', [UsuariosController::class, 'bloquear'])
    ->middleware('auth')
    ->name('usuarios.bloquear');

Route::post('/usuarios/{user}/desbloquear', [UsuariosController::class, 'desbloquear'])
    ->middleware('auth')
    ->name('usuarios.desbloquear');

Route::get('/usuarios/{user}', [UsuariosController::class, 'editar'])
    ->middleware('auth')
    ->name('usuarios.editar')
    ->whereNumber('user');

Route::get('/usuarios/{user}/ver', [UsuariosController::class, 'ver'])
    ->middleware('auth')
    ->name('usuarios.ver');

Route::put('/usuarios/{user}', [UsuariosController::class, 'modificar'])
    ->middleware('auth')
    ->name('usuarios.modificar');

Route::delete('/usuarios/{user}', [UsuariosController::class, 'eliminar'])
    ->middleware('auth')
    ->name('usuarios.eliminar');

// Participantes (monitoreo del evento en curso). La página la sirve el
// enrutador de módulos; aquí quedan la tabla y las fichas de sus modales.

Route::get('/participantes/datos', [ParticipantesController::class, 'datos'])
    ->middleware('auth')
    ->name('participantes.datos');

Route::get('/participantes/{participant}/ficha', [ParticipantesController::class, 'ficha'])
    ->middleware('auth')
    ->whereNumber('participant')
    ->name('participantes.ficha');

Route::get('/participantes/{participant}/lista-deseos', [ParticipantesController::class, 'listaDeseos'])
    ->middleware('auth')
    ->whereNumber('participant')
    ->name('participantes.lista-deseos');

Route::get('/participantes/{participant}/pistas', [ParticipantesController::class, 'pistas'])
    ->middleware('auth')
    ->whereNumber('participant')
    ->name('participantes.pistas');

Route::get('/participantes/{participant}/obsequios/consumibles', [ParticipantesController::class, 'consumibles'])
    ->middleware('auth')
    ->whereNumber('participant')
    ->name('participantes.consumibles');

Route::get('/participantes/{participant}/obsequios', [ParticipantesController::class, 'obsequios'])
    ->middleware('auth')
    ->whereNumber('participant')
    ->name('participantes.obsequios');

// Usuarios asociados (monitoreo de las parejas del evento). La página la sirve
// el enrutador de módulos; aquí queda la consulta del DataTable.

Route::get('/usuarios-asociados/datos', [UsuariosAsociadosController::class, 'datos'])
    ->middleware('auth')
    ->name('usuarios-asociados.datos');

// Roles (maestro de perfiles/roles). La página la sirve el enrutador de
// módulos; aquí quedan sus acciones.

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
    ->name('roles.editar')
    ->whereNumber('role');

Route::get('/roles/{role}/ver', [RolesController::class, 'ver'])
    ->middleware('auth')
    ->name('roles.ver');

Route::put('/roles/{role}', [RolesController::class, 'modificar'])
    ->middleware('auth')
    ->name('roles.modificar');

Route::delete('/roles/{role}', [RolesController::class, 'eliminar'])
    ->middleware('auth')
    ->name('roles.eliminar');

// Permisos (catálogo de permisos). La página la sirve el enrutador de
// módulos; aquí quedan sus acciones.

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
    ->name('permisos.editar')
    ->whereNumber('permission');

Route::get('/permisos/{permission}/ver', [PermisosController::class, 'ver'])
    ->middleware('auth')
    ->name('permisos.ver');

Route::put('/permisos/{permission}', [PermisosController::class, 'modificar'])
    ->middleware('auth')
    ->name('permisos.modificar');

Route::delete('/permisos/{permission}', [PermisosController::class, 'eliminar'])
    ->middleware('auth')
    ->name('permisos.eliminar');

// Categorías (clasificación de los módulos). La página la sirve el enrutador
// de módulos; aquí quedan sus acciones.

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
    ->name('categorias.editar')
    ->whereNumber('category');

Route::get('/categorias/{category}/ver', [CategoriasController::class, 'ver'])
    ->middleware('auth')
    ->name('categorias.ver');

Route::put('/categorias/{category}', [CategoriasController::class, 'modificar'])
    ->middleware('auth')
    ->name('categorias.modificar');

Route::delete('/categorias/{category}', [CategoriasController::class, 'eliminar'])
    ->middleware('auth')
    ->name('categorias.eliminar');

// Módulos (catálogo de módulos y sus rutas). La página la sirve el enrutador
// de módulos; aquí quedan sus acciones.

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
    ->name('modulos.editar')
    ->whereNumber('module');

Route::get('/modulos/{module:id}/ver', [ModulosController::class, 'ver'])
    ->middleware('auth')
    ->name('modulos.ver');

Route::put('/modulos/{module:id}', [ModulosController::class, 'modificar'])
    ->middleware('auth')
    ->name('modulos.modificar');

Route::delete('/modulos/{module:id}', [ModulosController::class, 'eliminar'])
    ->middleware('auth')
    ->name('modulos.eliminar');

// Matriz de perfiles (roles vs módulos y permisos). La página la sirve el
// enrutador de módulos; aquí quedan sus acciones.

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

// Página de cualquier módulo: /[categoria]/[modulo] (o /[modulo] cuando la
// categoría no lleva slug, como Perfil).
//
// El middleware resuelve la ruta contra el maestro de Módulos en cada
// petición: si cambia el slug del módulo o de su categoría, la URL cambia con
// ellos (y la anterior responde 301); si el módulo se elimina, la ruta deja de
// existir. Los módulos sin pantalla propia muestran el marcador de posición.
Route::get('/{path}', ModulePageController::class)
    ->where('path', '.*')
    ->middleware('module.access');

// Cualquier ruta no registrada responde 404 (y usa el shell si hay sesión).
Route::fallback(function () {
    abort(404);
});
