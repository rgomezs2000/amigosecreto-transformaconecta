<?php

use App\Http\Controllers\AmigoSecretoController;
use App\Http\Controllers\CategoriasController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContactoSoporteController;
use App\Http\Controllers\DinamicaController;
use App\Http\Controllers\EventoReinicioController;
use App\Http\Controllers\EventosController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\GranRevelacionController;
use App\Http\Controllers\GroupModerationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\IntercambioPistasController;
use App\Http\Controllers\InvitacionController;
use App\Http\Controllers\ListaDeseosController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\MatrizPerfilesController;
use App\Http\Controllers\MonitoreoAdminController;
use App\Http\Controllers\ModeracionBloqueosController;use App\Http\Controllers\ModeracionController;
use App\Http\Controllers\ModeracionGruposController;
use App\Http\Controllers\ModulePageController;
use App\Http\Controllers\ModulosController;
use App\Http\Controllers\NotificacionesController;
use App\Http\Controllers\ObsequiosAmigoController;
use App\Http\Controllers\ParticipantesController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PermisosController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\ShellController;
use App\Http\Controllers\SolicitudAdminController;
use App\Http\Controllers\SolicitudController;
use App\Http\Controllers\SorteoCiegoController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\UsuariosAsociadosController;
use App\Http\Controllers\UsuariosController;
use App\Http\Controllers\VerTuAmigoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

// Punto de entrada: sin sesión manda al login; con sesión muestra el panel.
Route::get('/', [HomeController::class, 'index'])->name('home');

// Cierre de sesión desde la pantalla de compatibilidad, con URL firmada.
Route::get('/compatibilidad/cerrar-sesion', function (Request $request) {
    abort_unless($request->hasValidSignature(), 403);

    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('compatibilidad', [
        'from' => (string) $request->query('from', ''),
        'motivo' => 'incompatible',
    ]);
})->name('compatibilidad.logout');

// Pantalla informativa para navegadores o sistemas no soportados.
Route::get('/compatibilidad', function (Request $request) {
    $from = (string) $request->query('from', '');

    if ($from !== '') {
        $scheme = strtolower((string) parse_url($from, PHP_URL_SCHEME));
        $fromHost = parse_url($from, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true)
            || $fromHost === null
            || strcasecmp($fromHost, $request->getHost()) !== 0) {
            $from = '';
        }
    }

    $motivo = $request->query('motivo') === 'incompatible' ? 'incompatible' : '';
    $legacyEngine = preg_match(
        '/MSIE\s|Trident\/|Edge\/|Presto\/|Konqueror\//i',
        (string) $request->userAgent()
    ) === 1;

    // Si se llega directamente a esta pantalla con un motor histórico y una
    // sesión abierta, también se cierra la sesión y se informa en la página.
    if ($legacyEngine) {
        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $motivo = 'incompatible';
    }

    $logoutUrl = Auth::check()
        ? URL::temporarySignedRoute(
            'compatibilidad.logout',
            now()->addMinutes(30),
            ['from' => $from ?: url('/')]
        )
        : '';

    return view('compatibilidad', [
        'from' => $from,
        'motivo' => $motivo,
        'logoutUrl' => $logoutUrl,
    ]);
})->name('compatibilidad');

// Pantalla de acceso.
Route::get('/login', [LoginController::class, 'index'])->name('login');

// Solicitudes públicas: prerregistro y recuperación de contraseña.
Route::get('/solicitudes/usuario-disponible', [SolicitudController::class, 'usuarioDisponible'])
    ->middleware('throttle:60,1')
    ->name('solicitudes.usuario');

Route::post('/solicitudes/registro', [SolicitudController::class, 'registro'])
    ->middleware('throttle:10,1')
    ->name('solicitudes.registro');

Route::post('/solicitudes/recuperacion', [SolicitudController::class, 'recuperacion'])
    ->middleware('throttle:5,1')
    ->name('solicitudes.recuperacion');

// Validación de usuario/correo y clave (responde JSON para el AJAX).
Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');

// Estado de la sesión única: el navegador lo consulta periódicamente.
Route::get('/sesion/estado', [SessionController::class, 'estado'])
    ->middleware('auth')
    ->name('sesion.estado');

// Menú vivo: el navegador lo consulta para reflejar cambios sin recargar.
Route::get('/menu/estado', [ShellController::class, 'menu'])
    ->middleware('auth')
    ->name('menu.estado');

// Tasa BCV para actualizar montos y referencias sin recargar.
Route::get('/tasa-bcv', [ExchangeRateController::class, 'show'])
    ->middleware('auth')
    ->name('tasa.bcv');

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

// Ficha completa del caso: datos, adjuntos, enlaces y sugerencia.
Route::post('/moderacion/reportes', [ModeracionController::class, 'crear'])
    ->middleware('auth')
    ->name('moderacion.crear');

Route::get('/moderacion/reportes/{report}/detalle', [ModeracionController::class, 'detalle'])
    ->middleware('auth')
    ->whereNumber('report')
    ->name('moderacion.detalle');

// Archivos adjuntos del caso, servidos fuera de la carpeta pública.
Route::get('/moderacion/adjuntos/{attachment}', [ModeracionController::class, 'adjunto'])
    ->middleware('auth')
    ->whereNumber('attachment')
    ->name('moderacion.adjuntos');

// Respuesta del administrador a una sugerencia.
Route::post('/moderacion/reportes/{report}/sugerencia', [ModeracionController::class, 'sugerencia'])
    ->middleware('auth')
    ->whereNumber('report')
    ->name('moderacion.reportes.sugerencia');

// Contacto y soporte del usuario: crear casos y hacer seguimiento.
Route::get('/contacto-soporte/datos', [ContactoSoporteController::class, 'datos'])
    ->middleware('auth')
    ->name('contacto-soporte.datos');

Route::post('/contacto-soporte/reportes', [ContactoSoporteController::class, 'crear'])
    ->middleware('auth')
    ->name('contacto-soporte.crear');

Route::get('/contacto-soporte/reportes/{report}/detalle', [ContactoSoporteController::class, 'detalle'])
    ->middleware('auth')
    ->whereNumber('report')
    ->name('contacto-soporte.detalle');

Route::post('/contacto-soporte/reportes/{report}/mensajes', [ContactoSoporteController::class, 'enviarMensaje'])
    ->middleware('auth')
    ->whereNumber('report')
    ->name('contacto-soporte.mensajes');

Route::get('/contacto-soporte/adjuntos/{attachment}', [ContactoSoporteController::class, 'adjunto'])
    ->middleware('auth')
    ->whereNumber('attachment')
    ->name('contacto-soporte.adjuntos');

Route::get('/moderacion/contadores', [ModeracionController::class, 'contadores'])
    ->middleware('auth')
    ->name('moderacion.contadores');

Route::post('/moderacion/reportes/{report}/tomar', [ModeracionController::class, 'tomar'])
    ->middleware('auth')
    ->name('moderacion.reportes.tomar');

Route::post('/moderacion/reportes/{report}/cerrar', [ModeracionController::class, 'cerrar'])
    ->middleware('auth')
    ->name('moderacion.reportes.cerrar');

// Remisión del caso a otro administrador: combobox de administradores y
// traspaso del chat del ticket.
Route::get('/moderacion/administradores', [ModeracionController::class, 'administradores'])
    ->middleware('auth')
    ->name('moderacion.administradores');

Route::post('/moderacion/reportes/{report}/remitir', [ModeracionController::class, 'remitir'])
    ->middleware('auth')
    ->name('moderacion.reportes.remitir');

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

// Gestión de solicitudes de cuenta (restablecer, desbloquear) y registro.
Route::post('/moderacion/solicitudes/{report}/restablecer', [SolicitudAdminController::class, 'restablecer'])
    ->middleware('auth')
    ->name('moderacion.solicitudes.restablecer');

Route::post('/moderacion/solicitudes/{report}/desbloquear', [SolicitudAdminController::class, 'desbloquear'])
    ->middleware('auth')
    ->name('moderacion.solicitudes.desbloquear');

Route::post('/moderacion/solicitudes/{report}/rechazar-registro', [SolicitudAdminController::class, 'rechazarRegistro'])
    ->middleware('auth')
    ->name('moderacion.solicitudes.rechazar');

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

// Sala cerrada: grupo con usuarios elegidos, sin enlace de invitación.
Route::post('/chat/sala', [ChatController::class, 'sala'])
    ->middleware('auth')
    ->name('chat.sala');

// Reenvío de mensajes: destinos posibles (grupos, administradores, amigos
// secretos y personas) y envío del mensaje a los elegidos.
Route::get('/chat/destinatarios', [ChatController::class, 'destinatarios'])
    ->middleware('auth')
    ->name('chat.destinatarios');

// Sala de difusión: conversación del dueño con destinatarios que reciben
// copia en su chat individual sin pertenecer a la conversación.
Route::post('/chat/broadcast', [ChatController::class, 'crearBroadcast'])
    ->middleware('auth')
    ->name('chat.broadcast.crear');

Route::post('/chat/mensajes/{message}/reenviar', [ChatController::class, 'reenviar'])
    ->middleware('auth')
    ->name('chat.reenviar');

Route::get('/chat/{conversation}/mensajes', [ChatController::class, 'mensajes'])
    ->middleware('auth')
    ->name('chat.mensajes');

// Búsqueda en el historial de la conversación (barra bajo la cabecera).
Route::get('/chat/{conversation}/buscar', [ChatController::class, 'buscar'])
    ->middleware('auth')
    ->name('chat.buscar');

// Encuestas y eventos adjuntos: solo en chats directos y salas de grupo.
Route::post('/chat/{conversation}/encuestas', [ChatController::class, 'crearEncuesta'])
    ->middleware('auth')
    ->name('chat.encuestas.crear');

Route::post('/chat/encuestas/{poll}/votar', [ChatController::class, 'votarEncuesta'])
    ->middleware('auth')
    ->name('chat.encuestas.votar');

Route::get('/chat/eventos', [ChatController::class, 'eventosDisponibles'])
    ->middleware('auth')
    ->name('chat.eventos');

Route::get('/chat/eventos/{event}', [ChatController::class, 'fichaEvento'])
    ->middleware('auth')
    ->name('chat.eventos.ficha');

Route::post('/chat/{conversation}/eventos', [ChatController::class, 'adjuntarEvento'])
    ->middleware('auth')
    ->name('chat.eventos.adjuntar');

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

Route::delete('/chat/{conversation}/mensaje-fijado/{message}', [ChatController::class, 'desfijarMensaje'])
    ->middleware('auth')
    ->name('chat.mensaje.desfijar.uno');

// Acciones por persona desde la pestaña Usuarios.
Route::post('/chat/usuarios/{user}/silenciar', [ChatController::class, 'silenciarUsuario'])
    ->middleware('auth')
    ->name('chat.usuarios.silenciar');

Route::post('/chat/usuarios/{user}/conversacion', [ChatController::class, 'abrirConversacion'])
    ->middleware('auth')
    ->name('chat.usuarios.conversacion');

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

Route::post('/chat/sala/{conversation}/miembros', [ChatController::class, 'agregarMiembroSala'])
    ->middleware('auth')
    ->name('chat.sala.miembros.agregar');

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

Route::post('/chat/mensajes/{message}/favorito', [ChatController::class, 'favoritoMensaje'])
    ->middleware('auth')
    ->name('chat.mensaje.favorito');

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

Route::post('/chat/{conversation}/grupos/agregar', [ChatController::class, 'agregarAGrupos'])
    ->middleware('auth')
    ->name('chat.grupos.agregar');

// Invitación personal al evento. El enlace es público: sin sesión manda al
// acceso y, después de entrar, vuelve a la invitación.
Route::get('/invitacion/{token}', [InvitacionController::class, 'abrir'])
    ->name('invitacion.abrir');

Route::get('/invitacion/{token}/datos', [InvitacionController::class, 'datos'])
    ->middleware('auth')
    ->name('invitacion.datos');

Route::post('/invitacion/{token}/aceptar', [InvitacionController::class, 'aceptar'])
    ->middleware('auth')
    ->name('invitacion.aceptar');

Route::post('/invitacion/{token}/rechazar', [InvitacionController::class, 'rechazar'])
    ->middleware('auth')
    ->name('invitacion.rechazar');

// Retiro voluntario del participante desde «Ver tu amigo».
Route::post('/amigo-secreto/ver-tu-amigo/retirar', [InvitacionController::class, 'retirar'])
    ->middleware('auth')
    ->name('ver-tu-amigo.retirar');

// Lista de deseos del participante: se activa al aceptar la invitación.
// La página la sirve el enrutador de módulos; aquí quedan sus acciones.
Route::get('/lista-de-deseos/datos', [ListaDeseosController::class, 'datos'])
    ->middleware('auth')
    ->name('lista-deseos.datos');

Route::post('/lista-de-deseos', [ListaDeseosController::class, 'crear'])
    ->middleware('auth')
    ->name('lista-deseos.crear');

Route::get('/lista-de-deseos/{item}', [ListaDeseosController::class, 'ver'])
    ->middleware('auth')
    ->whereNumber('item')
    ->name('lista-deseos.ver');

Route::put('/lista-de-deseos/{item}', [ListaDeseosController::class, 'modificar'])
    ->middleware('auth')
    ->whereNumber('item')
    ->name('lista-deseos.modificar');

Route::delete('/lista-de-deseos/{item}', [ListaDeseosController::class, 'eliminar'])
    ->middleware('auth')
    ->whereNumber('item')
    ->name('lista-deseos.eliminar');

// Intercambio de pistas: dos pestañas (enviadas y recibidas).
// La página la sirve el enrutador de módulos; aquí quedan sus acciones.
Route::get('/intercambio-de-pistas/datos', [IntercambioPistasController::class, 'datos'])
    ->middleware('auth')
    ->name('intercambio-pistas.datos');

Route::post('/intercambio-de-pistas', [IntercambioPistasController::class, 'crear'])
    ->middleware('auth')
    ->name('intercambio-pistas.crear');

Route::get('/intercambio-de-pistas/{clue}', [IntercambioPistasController::class, 'ver'])
    ->middleware('auth')
    ->whereNumber('clue')
    ->name('intercambio-pistas.ver');

Route::put('/intercambio-de-pistas/{clue}', [IntercambioPistasController::class, 'modificar'])
    ->middleware('auth')
    ->whereNumber('clue')
    ->name('intercambio-pistas.modificar');

Route::delete('/intercambio-de-pistas/{clue}', [IntercambioPistasController::class, 'eliminar'])
    ->middleware('auth')
    ->whereNumber('clue')
    ->name('intercambio-pistas.eliminar');

// Gran revelación: se abre el día exacto del intercambio.
Route::get('/gran-revelacion/datos', [GranRevelacionController::class, 'datos'])
    ->middleware('auth')
    ->name('gran-revelacion.datos');

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

// Fase Unión: envío de invitaciones y gestión de la participación.
Route::post('/eventos/{event}/invitar', [EventosController::class, 'invitar'])
    ->middleware('auth')
    ->name('eventos.invitar');

Route::post('/eventos/{event}/participantes/{user}/invitar', [EventosController::class, 'invitarParticipante'])
    ->middleware('auth')
    ->whereNumber('user')
    ->name('eventos.participantes.invitar');

Route::post('/eventos/{event}/participantes/{user}/expulsar', [EventosController::class, 'expulsar'])
    ->middleware('auth')
    ->whereNumber('user')
    ->name('eventos.participantes.expulsar');

Route::post('/eventos/{event}/participantes/{user}/descartar', [EventosController::class, 'descartar'])
    ->middleware('auth')
    ->whereNumber('user')
    ->name('eventos.participantes.descartar');

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

Route::get('/usuarios/solicitud', [UsuariosController::class, 'solicitud'])
    ->middleware('auth')
    ->name('usuarios.solicitud');

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

// Consulta de los módulos de administración (lista de deseos, usuarios
// obsequiados y pistas): tabla de usuarios con búsqueda y filtro de estatus.
Route::get('/monitoreo/{tipo}/datos', [MonitoreoAdminController::class, 'datos'])
    ->middleware('auth')
    ->whereIn('tipo', ['deseos', 'obsequios', 'pistas'])
    ->name('monitoreo.datos');

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
