<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\HelpArticle;
use App\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Contenido del centro de ayuda.
 *
 * Es estático y vive en la base de datos: las vistas de Acerca de, la Guía y
 * las FAQ solo dibujan lo que encuentran aquí. Este seeder es la fuente de
 * verdad para el despliegue y actualiza el contenido cuando cambia el sistema,
 * sin tocar filas que no cambiaron.
 */
class HelpContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->sembrarParametros();

        $modules = Module::query()
            ->whereIn('code', ['acerca-de', 'ayuda', 'faq'])
            ->get()
            ->keyBy('code');

        foreach ($this->articulos() as $articulo) {
            $module = $modules->get($articulo['module']);

            // Si el maestro todavía no tiene los módulos, no hay dónde colgar
            // el contenido. Ocurre solo en instalaciones incompletas.
            if ($module === null) {
                continue;
            }

            $article = HelpArticle::query()->firstOrNew([
                'module_id' => $module->id,
                'slug' => $articulo['slug'],
            ]);

            $article->fill([
                'title' => $articulo['title'],
                'kind' => $articulo['kind'],
                'audience' => $articulo['audience'],
                'topic' => $articulo['topic'],
                'icon' => $articulo['icon'],
                'body' => $articulo['body'],
                'sort_order' => $articulo['sort_order'],
                'is_published' => true,
            ]);

            if (! $article->exists || $article->isDirty()) {
                $article->save();
            }
        }
    }

    /**
     * Parámetros que la interfaz muestra (nombre y versión del sistema).
     */
    private function sembrarParametros(): void
    {
        $parametros = [
            'app.name' => 'Amigo Secreto Inclusivo',
            'app.tagline' => 'Un intercambio claro, tranquilo y a tu ritmo.',
            'app.version' => '0.1',
            'app.version_status' => 'Primera versión funcional',
            'app.version_notes' => 'El contenido de esta guía se actualiza cuando cambia el sistema.',
        ];

        foreach ($parametros as $key => $value) {
            AppSetting::putValue($key, $value);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function articulos(): array
    {
        return array_merge(
            $this->acercaDe(),
            $this->adminAcercaDe(),
            $this->guia(),
            $this->adminGuia(),
            $this->preguntasFrecuentes(),
            $this->adminPreguntasFrecuentes()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function acercaDe(): array
    {
        return [
            $this->seccion(
                'acerca-de',
                'que-es',
                'Qué es Amigo Secreto Inclusivo',
                'fa-circle-info',
                10,
                [
                    $this->p('Amigo Secreto Inclusivo es una aplicación web para organizar el intercambio de regalos del amigo secreto en un grupo pequeño. Reúne en un solo lugar el sorteo, la lista de deseos, las pistas anónimas y la revelación final.'),
                    $this->p('Está pensada para personas con TEA Grado 1 (síndrome de Asperger) y, en general, para quienes prefieren instrucciones claras, pocas sorpresas y un entorno tranquilo. También funciona para cualquier grupo que quiera una dinámica ordenada.'),
                    $this->note('La aplicación no es una red social abierta. Solo participas en los eventos a los que te invita la organización.', 'info', 'Un espacio privado'),
                ]
            ),
            $this->seccion(
                'acerca-de',
                'para-quien',
                'Para quién está pensada',
                'fa-users',
                20,
                [
                    $this->ul([
                        'Personas que participan en el intercambio y quieren saber qué hacer en cada momento.',
                        'Organizadores que crean el evento, inscriben a los participantes y acompañan la dinámica.',
                        'Personas que necesitan explicar sus preferencias sensoriales para recibir un regalo que no les cause incomodidad.',
                        'Grupos familiares, de trabajo, de estudio o de amigos que quieren una experiencia predecible.',
                    ]),
                ]
            ),
            $this->seccion(
                'acerca-de',
                'como-funciona',
                'Cómo funciona, en cinco pasos',
                'fa-route',
                30,
                [
                    $this->p('La dinámica avanza en cinco pasos. El panel muestra siempre en cuál estás. No puedes saltarte un paso ni adelantar el siguiente por tu cuenta.'),
                    $this->steps([
                        ['title' => 'Creación y unión', 'text' => 'La organización crea el evento e inscribe a las personas. Cada participante entra con su cuenta.'],
                        ['title' => 'Sorteo ciego', 'text' => 'El sistema reparte los nombres al azar. Nadie se saca a sí mismo y, por defecto, no se forman parejas cruzadas.'],
                        ['title' => 'Lista de deseos', 'text' => 'Cada persona escribe qué le gustaría recibir y qué prefiere evitar.'],
                        ['title' => 'Intercambio de pistas', 'text' => 'Cada quien deja pistas anónimas a la persona que le regala.'],
                        ['title' => 'Gran revelación', 'text' => 'El día del intercambio se revela quién era cada amigo secreto y se entregan los regalos.'],
                    ]),
                ]
            ),
            $this->seccion(
                'acerca-de',
                'accesibilidad',
                'Decisiones de accesibilidad',
                'fa-universal-access',
                40,
                [
                    $this->p('El diseño sigue criterios de accesibilidad cognitiva y baja carga sensorial.'),
                    $this->ul([
                        'Textos directos, sin ironías ni dobles sentidos.',
                        'Flujo lineal: siempre sabes en qué paso estás y qué viene después.',
                        'Colores calmados y un solo color de acento para las acciones.',
                        'Sin animaciones ni transiciones bruscas; el movimiento se puede desactivar desde el sistema operativo.',
                        'Texto base de 16 px, interlineado amplio y botones grandes.',
                        'Avisos visuales dentro de la aplicación; el sonido de las notificaciones se puede apagar.',
                        'Plantillas guiadas para las pistas, para no tener que improvisar.',
                        'La lista de deseos incluye un espacio explícito para restricciones sensoriales y alérgenos.',
                    ]),
                ]
            ),
            $this->seccion(
                'acerca-de',
                'que-incluye',
                'Qué incluye esta versión',
                'fa-layer-group',
                50,
                [
                    $this->p('Esta versión reúne las piezas principales del sistema.'),
                    $this->ul([
                        'Cuentas con acceso por usuario y contraseña o con Google.',
                        'Panel con contador de días y horas hasta el intercambio, y barra de pasos.',
                        'Sorteo ciego automático y consulta ilimitada de tu amigo.',
                        'Lista de deseos con gustos y restricciones.',
                        'Pistas anónimas y chat privado entre cada pareja.',
                        'Chat general del evento y chat de grupo, con opciones de reportar y bloquear.',
                        'Notificaciones internas con preferencias de push, sonido y avisos visuales.',
                        'Centro de ayuda con Acerca de, esta guía y preguntas frecuentes.',
                    ]),
                    $this->note('Algunas pantallas se habilitan por etapas. Si un módulo muestra «Este módulo está en construcción», todavía no está disponible; la organización avisará cuando se active.', 'important', 'Funciones por etapas'),
                ]
            ),
            $this->seccion(
                'acerca-de',
                'privacidad',
                'Tus datos y tu privacidad',
                'fa-shield-halved',
                60,
                [
                    $this->p('La información que compartes se usa solo para la dinámica del evento.'),
                    $this->ul([
                        'Tu lista de deseos y tus restricciones solo las ve la persona que te regala y la administración.',
                        'Las pistas son anónimas: quien las recibe no ve el nombre de quien las envía.',
                        'El chat del amigo secreto es privado entre las dos personas de la pareja.',
                        'Puedes reportar mensajes o conversaciones y bloquear a una persona desde el chat.',
                        'La administración puede revisar reportes para cuidar la convivencia del grupo.',
                    ]),
                ]
            ),
            $this->seccion(
                'acerca-de',
                'version',
                'Versión del sistema',
                'fa-code-branch',
                70,
                [
                    $this->p('Es la primera versión funcional del sistema y sigue en evolución.'),
                    $this->versionBlock(),
                    $this->facts([
                        ['label' => 'Estado', 'value' => 'Primera versión funcional'],
                        ['label' => 'Dinámica', 'value' => 'Cinco pasos lineales'],
                        ['label' => 'Accesibilidad', 'value' => 'TEA Grado 1 y baja carga sensorial'],
                        ['label' => 'Idioma', 'value' => 'Español'],
                    ]),
                    $this->note('Si una función no aparece todavía, no está disponible en esta versión. Consulta las preguntas frecuentes o escribe a la organización.', 'info'),
                ]
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function guia(): array
    {
        return [
            $this->seccion(
                'ayuda',
                'antes-de-empezar',
                'Antes de empezar',
                'fa-flag-checkered',
                10,
                [
                    $this->p('Para participar necesitas una cuenta activa y estar inscrito en el evento. La organización crea el evento e inscribe a las personas; tú no creas el evento.'),
                    $this->p('Lo que necesitas:'),
                    $this->ul([
                        'Un navegador actualizado en computadora, tableta o teléfono.',
                        'Tu usuario y contraseña, o una cuenta de Google.',
                        'Estar inscrito en el evento del grupo.',
                        'Al menos dos participantes activos para que el sorteo funcione.',
                    ]),
                    $this->note('Si no ves el evento o un módulo no aparece en tu menú, escribe a la organización. Puede que tu cuenta aún no tenga el acceso.', 'info'),
                    $this->note('Esta guía describe la dinámica completa. Si una pantalla muestra «Este módulo está en construcción», esa etapa todavía no está habilitada; la organización avisará cuando lo esté.', 'important', 'Etapas que se habilitan por partes'),
                ]
            ),
            $this->seccion(
                'ayuda',
                'entrar-al-sistema',
                'Entrar al sistema',
                'fa-right-to-bracket',
                20,
                [
                    $this->steps([
                        'Abre la dirección del sistema que te compartió la organización.',
                        'Escribe tu usuario y contraseña, o pulsa «Continuar con Google».',
                        'Al entrar verás el panel de control con el evento activo.',
                    ]),
                    $this->p('Si la cuenta está bloqueada, el sistema muestra un aviso. La administración puede desbloquearla o restablecer la contraseña desde el módulo Usuarios.'),
                    $this->note('No compartas tu contraseña. Cada persona debe entrar con su propia cuenta.', 'important'),
                ]
            ),
            $this->seccion(
                'ayuda',
                'el-panel',
                'El panel de control',
                'fa-table-columns',
                30,
                [
                    $this->p('El panel reúne la información del evento y el acceso a los módulos.'),
                    $this->ul([
                        'Encabezado: nombre del evento y fecha del intercambio.',
                        'Contador: días y horas que faltan para el intercambio.',
                        'Barra de pasos: los cinco pasos de la dinámica, con el paso activo resaltado.',
                        'Campana: notificaciones internas. El número indica cuántas no has leído.',
                        'Menú: módulos agrupados por categoría. En móvil se abre con el botón de tres líneas.',
                        'Tasa BCV: referencia del dólar oficial para calcular un presupuesto, si el grupo la usa.',
                    ]),
                    $this->note('El paso activo no cambia por tu cuenta. Lo habilita la organización o lo marca el calendario del evento.', 'info'),
                ]
            ),
            $this->seccion(
                'ayuda',
                'paso-1',
                'Paso 1: Creación y unión',
                'fa-people-group',
                40,
                [
                    $this->p('La organización crea el evento, define la fecha del intercambio e inscribe a los participantes. Mientras el evento está en borrador o inscripción, se pueden agregar o retirar personas.'),
                    $this->ul([
                        'Si ya estás inscrito, verás el evento en el panel.',
                        'Si falta tu inscripción, avisa a la organización.',
                        'La fecha del intercambio activa el contador y la etapa de la gran revelación.',
                    ]),
                    $this->note('No compartas el enlace del sistema fuera del grupo. El acceso es personal.', 'important'),
                ]
            ),
            $this->seccion(
                'ayuda',
                'paso-2',
                'Paso 2: Sorteo ciego',
                'fa-handshake',
                50,
                [
                    $this->p('Cuando la organización habilita el sorteo, aparece el módulo «Sorteo ciego».'),
                    $this->steps([
                        'Entra a «Sorteo ciego» desde el menú o desde el panel.',
                        'Pulsa «Descubrir a mi Amigo Secreto». El reparto es al azar y se hace una sola vez.',
                        'Lee la tarjeta con el nombre y las preferencias de tu amigo.',
                        'Usa «Ver de nuevo» cuando quieras. No se vuelve a sortear.',
                    ]),
                    $this->p('El reparto evita que te saques a ti mismo y, por defecto, evita parejas cruzadas (A regala a B y B regala a A). La organización puede permitir parejas mutuas si el grupo lo decide.'),
                    $this->p('El sorteo se cierra cuando todos los participantes activos descubren a su amigo. En el módulo verás el avance: «Han elegido X de Y participantes».'),
                    $this->note('No reveles el nombre de tu amigo secreto. La gracia del juego es que sea secreto hasta la gran revelación.', 'important'),
                    $this->note('Con dos participantes, el único reparto posible es cruzado. La organización debe habilitar las parejas mutuas para ese caso.', 'info'),
                ]
            ),
            $this->seccion(
                'ayuda',
                'paso-3',
                'Paso 3: Lista de deseos',
                'fa-list-check',
                60,
                [
                    $this->p('La lista de deseos ayuda a quien te regala a elegir algo que de verdad quieras o necesites.'),
                    $this->ul([
                        '«Lo que sí me gusta / Deseo»: ideas, gustos y categorías (café, chocolates, libros, ropa, etc.).',
                        '«Restricciones y cosas que NO me gustan»: texturas, olores, sabores, alérgenos o tipos de regalo que prefieres evitar.',
                    ]),
                    $this->p('Cómo completarla:'),
                    $this->steps([
                        'Escribe entre tres y cinco ideas concretas, sin exigir marcas ni precios.',
                        'Anota tus restricciones con claridad. Ejemplo: «No me gustan los perfumes fuertes ni la ropa de lana».',
                        'Si no tienes restricciones, escribe «Ninguna».',
                        'Guarda los cambios y revísalos antes de la fecha del intercambio.',
                    ]),
                    $this->note('Solo la persona que te regala y la administración pueden ver tu lista. El resto del grupo no la ve.', 'info'),
                    $this->note('Las restricciones sensoriales y los alérgenos no son un capricho: son información necesaria para elegir un regalo seguro y cómodo.', 'important'),
                ]
            ),
            $this->seccion(
                'ayuda',
                'paso-4',
                'Paso 4: Intercambio de pistas',
                'fa-lightbulb',
                70,
                [
                    $this->p('Las pistas son mensajes anónimos que quien te regala te deja para ayudarte a adivinar. Tú también puedes dejar pistas a tu amigo secreto.'),
                    $this->p('Cómo enviar una pista:'),
                    $this->steps([
                        'Entra a «Intercambio de pista».',
                        'Elige una plantilla. Por ejemplo: «Mi color favorito es [color]» o «Me encanta [algo]».',
                        'Completa el espacio con una respuesta corta.',
                        'Revisa el texto y envíalo. Tu nombre no aparece.',
                    ]),
                    $this->p('Las pistas que recibes aparecen ordenadas en «Ver tu amigo», de la más reciente a la más antigua. Puedes leerlas cuando quieras; no hay notificaciones con sonido por defecto.'),
                    $this->note('No es obligatorio usar plantillas, pero ayudan a evitar la presión de improvisar una adivinanza.', 'info'),
                    $this->note('No escribas tu nombre ni datos que te identifiquen si quieres conservar el anonimato.', 'important'),
                ]
            ),
            $this->seccion(
                'ayuda',
                'paso-5',
                'Paso 5: Gran revelación',
                'fa-star',
                80,
                [
                    $this->p('El día del intercambio se activa la gran revelación. Verás dos datos: quién era tu amigo secreto y quién te regaló a ti.'),
                    $this->ul([
                        'Tu amigo secreto: la persona a quien te tocó regalar.',
                        'Quien te regaló: la persona que tenía tu nombre.',
                        'Las pistas y la carta o notas que se acumularon durante la dinámica.',
                    ]),
                    $this->p('La revelación puede incluir un efecto suave de confeti. Si prefieres evitarlo, se puede desactivar. La información importante siempre aparece escrita, no solo en el efecto.'),
                    $this->note('Si el evento se cierra antes de la fecha, la organización puede activar la revelación manualmente.', 'info'),
                ]
            ),
            $this->seccion(
                'ayuda',
                'modulos',
                'Los módulos del menú',
                'fa-table-cells-large',
                90,
                [
                    $this->p('El menú se organiza por categorías. Cada módulo abre una pantalla distinta.'),
                    $this->facts([
                        ['label' => 'General', 'value' => 'Inicio y Perfil.'],
                        ['label' => 'Amigo secreto', 'value' => 'Sorteo ciego, Ver tu amigo, Lista de deseos, Intercambio de pista y Gran revelación.'],
                        ['label' => 'Mensajes', 'value' => 'Chat.'],
                        ['label' => 'Ayuda', 'value' => 'Acerca de, esta guía, FAQ y Contacto y soporte.'],
                        ['label' => 'Sesión', 'value' => 'Salir.'],
                    ]),
                    $this->note('Los módulos que ves dependen de tu perfil. Si falta alguno, la administración puede concederlo desde la Matriz de perfiles.', 'info'),
                    $this->note('«Este módulo está en construcción» significa que la pantalla todavía no está disponible.', 'important'),
                ]
            ),
            $this->seccion(
                'ayuda',
                'chat',
                'Chat y mensajes',
                'fa-comments',
                100,
                [
                    $this->p('El chat reúne las conversaciones del evento en un solo lugar. Se abre con el botón flotante de la esquina inferior derecha.'),
                    $this->ul([
                        'Chat del evento: mensajes para todo el grupo.',
                        'Chat del amigo secreto: conversación privada entre la pareja del sorteo.',
                        'Chats de grupo: conversaciones con las personas que la organización agregue.',
                        'Adjuntos, respuestas y reenvíos según lo permita la conversación.',
                        'Reportar un mensaje o una conversación, y bloquear a una persona.',
                    ]),
                    $this->note('Los mensajes se guardan para que puedas leerlos después. No se envían notificaciones con sonido si las apagas.', 'info'),
                    $this->note('Las conversaciones pueden ser revisadas por la administración si hay un reporte. Usa el chat con respeto.', 'important'),
                ]
            ),
            $this->seccion(
                'ayuda',
                'notificaciones',
                'Notificaciones y sonido',
                'fa-bell',
                110,
                [
                    $this->p('La campana muestra los avisos del sistema: nuevas pistas, cambios del evento, recordatorios y mensajes de la organización.'),
                    $this->ul([
                        'Alertas push: aviso en el navegador o dispositivo, si lo permites.',
                        'Sonido: tono al recibir un aviso. Puedes apagarlo.',
                        'Avisos visuales: el punto y el número en la campana.',
                    ]),
                    $this->steps([
                        'Abre la campana.',
                        'En «Cómo quieres tus avisos», activa o desactiva push, sonido y avisos visuales.',
                        'El cambio se guarda al instante.',
                    ]),
                    $this->note('Si el sonido te resulta incómodo, apágalo. Los avisos visuales seguirán funcionando.', 'info'),
                ]
            ),
            $this->seccion(
                'ayuda',
                'recomendaciones',
                'Recomendaciones para una buena experiencia',
                'fa-compass',
                120,
                [
                    $this->ul([
                        'Lee el paso activo antes de actuar; el panel siempre dice qué toca.',
                        'Completa tu lista de deseos temprano.',
                        'Usa las plantillas de pistas si no sabes qué escribir.',
                        'Respeta las restricciones de tu amigo secreto.',
                        'No reveles nombres antes de la gran revelación.',
                        'Si algo te genera ansiedad, detente y consulta esta guía o a la organización.',
                        'Puedes volver a leer esta guía cuando quieras.',
                    ]),
                    $this->note('No hay prisa. La dinámica está diseñada para avanzar sin presión.', 'info'),
                ]
            ),
            $this->seccion(
                'ayuda',
                'problemas',
                'Si algo no funciona',
                'fa-life-ring',
                130,
                [
                    $this->facts([
                        ['label' => 'No veo el evento', 'value' => 'Puede que no estés inscrito. Avisa a la organización.'],
                        ['label' => 'No veo un módulo', 'value' => 'Tu perfil no tiene acceso. Pide que lo concedan en la Matriz de perfiles.'],
                        ['label' => 'El sorteo está cerrado', 'value' => 'El evento no ha comenzado, está suspendido, ya concluyó o ya finalizó.'],
                        ['label' => 'No puedo entrar', 'value' => 'Revisa usuario y contraseña. Si la cuenta está bloqueada, pide a la administración que la desbloquee.'],
                        ['label' => 'La página dice «en construcción»', 'value' => 'Esa pantalla todavía no está disponible.'],
                        ['label' => 'Necesito ayuda con la dinámica', 'value' => 'Consulta las preguntas frecuentes o escribe a la organización.'],
                    ]),
                    $this->note('El módulo «Contacto y soporte» ya aparece en el menú, pero su pantalla todavía está en construcción. Por ahora, el canal de ayuda es la organización del evento.', 'info'),
                ]
            ),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function preguntasFrecuentes(): array
    {
        $acceso = 'Acceso y cuenta';
        $dinamica = 'La dinámica';
        $deseos = 'Lista de deseos';
        $pistas = 'Pistas y chat';
        $revelacion = 'Revelación y cierre';
        $soporte = 'Ayuda y soporte';

        return [
            $this->faq('como-entro', $acceso, 110, '¿Cómo entro al sistema?', [
                $this->p('Abre la dirección que te compartió la organización. Puedes entrar con tu usuario y contraseña o con una cuenta de Google. Si es tu primera vez, la organización te dará tus datos de acceso.'),
            ]),
            $this->faq('olvide-clave', $acceso, 120, '¿Olvidé mi contraseña. Qué hago?', [
                $this->p('No hay recuperación automática en esta versión. Pide a la administración que restablezca tu contraseña desde Usuarios. Te dará una clave temporal y podrás cambiarla en Perfil.'),
            ]),
            $this->faq('cuenta-bloqueada', $acceso, 130, '¿Por qué mi cuenta está bloqueada?', [
                $this->p('La cuenta se bloquea después de varios intentos fallidos de acceso, o si la administración la bloquea. Escribe a la organización para que la desbloquee. No intentes entrar muchas veces seguidas.'),
            ]),
            $this->faq('no-veo-modulo', $acceso, 140, '¿Por qué no veo un módulo en el menú?', [
                $this->p('Los módulos dependen de tu perfil y de los accesos que te haya dado la administración. Si falta uno, pide que lo concedan en la Matriz de perfiles. También puede estar inactivo.'),
            ]),
            $this->faq('google', $acceso, 150, '¿Puedo entrar con Google?', [
                $this->p('Sí, si la organización habilitó el acceso con Google. Pulsa «Continuar con Google» y autoriza con la cuenta que registraste. Si tu cuenta no coincide, pide ayuda a la administración.'),
            ]),
            $this->faq('cambiar-clave', $acceso, 160, '¿Cómo cambio mi contraseña?', [
                $this->steps([
                    'Entra a Perfil.',
                    'Pulsa «Cambiar contraseña».',
                    'Escribe la clave actual y la nueva.',
                    'Guarda el cambio.',
                ]),
            ]),
            $this->faq('cerrar-sesion', $acceso, 170, '¿Cómo cierro la sesión?', [
                $this->p('Abre el menú y pulsa «Salir». Si usas un equipo compartido, cierra también el navegador.'),
            ]),

            $this->faq('que-es', $dinamica, 210, '¿Qué es el amigo secreto?', [
                $this->p('Es un intercambio de regalos en el que cada persona tiene un amigo secreto al azar. Le deja pistas anónimas y le regala algo el día del intercambio. Al final se revela quién era cada uno.'),
            ]),
            $this->faq('cuantos-participantes', $dinamica, 220, '¿Cuántas personas pueden participar?', [
                $this->p('Mínimo dos participantes activos. Con dos, el reparto es necesariamente cruzado; la organización debe permitir parejas mutuas. Con tres o más, el sistema evita que dos personas se regalen entre sí, salvo que el grupo lo permita.'),
            ]),
            $this->faq('me-saco', $dinamica, 230, '¿Puedo sacarme a mí mismo?', [
                $this->p('No. El sistema reparte los nombres de forma que nadie se saque a sí mismo.'),
            ]),
            $this->faq('parejas-cruzadas', $dinamica, 240, '¿Puede tocarme la misma persona que yo elegí?', [
                $this->p('Por defecto no. El sistema evita parejas cruzadas (A regala a B y B regala a A). La organización puede permitirlas si el grupo lo decide.'),
            ]),
            $this->faq('cuando-sorteo', $dinamica, 250, '¿Cuándo se hace el sorteo?', [
                $this->p('Cuando la organización habilita el evento y abre la etapa de sorteo. Entra a «Sorteo ciego» y pulsa «Descubrir a mi Amigo Secreto». Si el módulo dice que está cerrado, el evento aún no comenzó, está suspendido o ya terminó.'),
            ]),
            $this->faq('ver-de-nuevo', $dinamica, 260, '¿Puedo volver a ver mi amigo secreto?', [
                $this->p('Sí, sin límite. Usa «Ver de nuevo» en Sorteo ciego o entra a Ver tu amigo. No se vuelve a sortear.'),
            ]),
            $this->faq('nadie-elige', $dinamica, 270, '¿Qué pasa si alguien no descubre a su amigo?', [
                $this->p('El sorteo no se cierra hasta que todos los participantes activos descubren al suyo. Si alguien no puede, la organización puede reiniciar el sorteo o ajustar la lista de participantes.'),
            ]),
            $this->faq('cambiar-amigo', $dinamica, 280, '¿Puedo cambiar de amigo secreto?', [
                $this->p('No por tu cuenta. Solo la administración puede reiniciar el sorteo, y eso cambia el reparto para todo el grupo. Si hay un error, avisa a la organización.'),
            ]),
            $this->faq('suspension', $dinamica, 290, '¿Qué pasa si el evento se suspende o se pospone?', [
                $this->p('La dinámica se detiene y se conserva el avance. Cuando la organización reactive el evento, continúa en la misma etapa. El sorteo y las pistas no se borran.'),
            ]),

            $this->faq('para-que-deseos', $deseos, 310, '¿Para qué sirve la lista de deseos?', [
                $this->p('Para que quien te regala sepa qué te gustaría recibir y qué debes evitar. No es una lista de obligaciones ni un compromiso de compra.'),
            ]),
            $this->faq('quien-ve-deseos', $deseos, 320, '¿Quién puede ver mi lista de deseos?', [
                $this->p('Solo la persona que te regala y la administración. El resto del grupo no puede verla.'),
            ]),
            $this->faq('restricciones', $deseos, 330, '¿Por qué hay un espacio para restricciones?', [
                $this->p('Para cuidar la accesibilidad sensorial y las alergias. Allí puedes anotar texturas, olores, sabores o materiales que prefieres evitar. Es información importante, no un capricho.'),
            ]),
            $this->faq('sin-restricciones', $deseos, 340, '¿Qué escribo si no tengo restricciones?', [
                $this->p('Escribe «Ninguna». Así quien te regala sabe que puede elegir con libertad.'),
            ]),
            $this->faq('presupuesto', $deseos, 350, '¿Debo pedir cosas caras?', [
                $this->p('No. La lista es una guía, no una exigencia. El grupo puede acordar un presupuesto. La tasa BCV del panel es solo una referencia.'),
            ]),
            $this->faq('editar-deseos', $deseos, 360, '¿Puedo cambiar mi lista después de guardarla?', [
                $this->p('Sí, mientras el evento lo permita. Entra a Lista de deseos, modifica el texto y guarda. Avisa a tu amigo secreto si haces un cambio importante.'),
            ]),

            $this->faq('enviar-pista', $pistas, 410, '¿Cómo envío una pista?', [
                $this->steps([
                    'Entra a «Intercambio de pista».',
                    'Elige una plantilla o escribe un texto corto.',
                    'Completa la pista y envíala.',
                    'Tu nombre no aparece.',
                ]),
            ]),
            $this->faq('pista-anonima', $pistas, 420, '¿Mi amigo sabrá que la pista es mía?', [
                $this->p('No. La pista se muestra sin el nombre de quien la envía. El anonimato se mantiene hasta la gran revelación.'),
            ]),
            $this->faq('pista-libre', $pistas, 430, '¿Puedo escribir una pista libre, sin plantilla?', [
                $this->p('Sí. Las plantillas son una ayuda para quien prefiere una estructura. Si escribes libre, evita datos que te identifiquen.'),
            ]),
            $this->faq('cuando-pistas', $pistas, 440, '¿Cuándo puedo enviar pistas?', [
                $this->p('Durante la etapa de intercambio de pistas. Si el módulo no está disponible, espera a que la organización habilite la etapa.'),
            ]),
            $this->faq('sonido', $pistas, 450, '¿Las notificaciones hacen ruido?', [
                $this->p('Solo si el sonido está activo. Puedes apagarlo en la campana, en «Cómo quieres tus avisos». Los avisos visuales siguen funcionando.'),
            ]),
            $this->faq('bloquear', $pistas, 460, '¿Cómo bloqueo o reporto a alguien?', [
                $this->p('Abre el chat, entra a la conversación y usa las opciones de la persona o del mensaje. Puedes reportar un mensaje, una conversación o bloquear a alguien. La administración revisa los reportes.'),
            ]),

            $this->faq('cuando-revelacion', $revelacion, 510, '¿Cuándo se revela quién es mi amigo secreto?', [
                $this->p('El día del intercambio, cuando la organización activa la gran revelación. Si el evento se cierra antes, la organización también puede activarla.'),
            ]),
            $this->faq('quien-me-regalo', $revelacion, 520, '¿Cómo sé quién me regaló?', [
                $this->p('En la gran revelación verás dos nombres: tu amigo secreto (a quien regalaste) y la persona que te regaló a ti.'),
            ]),
            $this->faq('pistas-despues', $revelacion, 530, '¿Puedo leer las pistas después de la revelación?', [
                $this->p('Sí. Se conservan en Ver tu amigo y en la pantalla de la gran revelación, junto con las notas de la dinámica.'),
            ]),
            $this->faq('fin-evento', $revelacion, 540, '¿Qué pasa cuando el evento termina?', [
                $this->p('El sorteo y las etapas se cierran. Puedes seguir consultando la información del evento y las pistas. La organización puede crear un evento nuevo para el siguiente año.'),
            ]),

            $this->faq('contacto', $soporte, 610, '¿Cómo contacto al soporte?', [
                $this->p('El módulo «Contacto y soporte» ya aparece en el menú, pero su pantalla todavía está en construcción. Por ahora, escribe al administrador o a la organización del evento por el canal que usaron para invitarte.'),
            ]),
            $this->faq('reportar-error', $soporte, 620, '¿Cómo reporto un error o una sugerencia?', [
                $this->p('Cuéntale a la organización qué intentabas hacer, qué esperabas y qué ocurrió. Si puedes, incluye una captura de pantalla. No compartas contraseñas.'),
            ]),
            $this->faq('version', $soporte, 630, '¿Qué versión del sistema estoy usando?', [
                $this->p('La versión actual aparece en Acerca de, junto al estado del sistema. Esta guía y las preguntas frecuentes se actualizan cuando cambia el sistema.'),
            ]),
            $this->faq('actualizacion', $soporte, 640, '¿Con qué frecuencia se actualiza la ayuda?', [
                $this->p('El contenido se revisa con cada cambio del sistema. Si una función aún no aparece, no está disponible en esta versión.'),
            ]),
        ];
    }

    /**
     * Contenido de administración de «Acerca de».
     *
     * Solo se muestra a cuentas con rol Administrador.
     *
     * @return array<int, array<string, mixed>>
     */
    private function adminAcercaDe(): array
    {
        return [
            $this->seccion(
                'acerca-de',
                'admin-rol',
                'El rol de administración',
                'fa-user-shield',
                110,
                [
                    $this->p('La cuenta con rol Administrador ve este bloque y puede entrar a los módulos de gestión del sistema. Las cuentas con rol Participante no reciben este contenido.'),
                    $this->facts([
                        ['label' => 'Rol que ve este bloque', 'value' => 'Administrador'],
                        ['label' => 'Rol de los participantes', 'value' => 'Participante'],
                        ['label' => 'Acceso a módulos', 'value' => 'Se concede por perfil en la Matriz de perfiles'],
                        ['label' => 'Contenido exclusivo', 'value' => 'Se excluye de la consulta para los participantes'],
                    ]),
                    $this->note('Ocultar el bloque con estilos no sería suficiente. El contenido de administración no se envía a las cuentas que no son administradoras.', 'important'),
                ],
                HelpArticle::AUDIENCE_ADMIN
            ),
            $this->seccion(
                'acerca-de',
                'admin-accesos',
                'Perfiles, módulos y accesos',
                'fa-key',
                120,
                [
                    $this->p('El rol no concede módulos por sí solo. El acceso a cada pantalla se concede por perfil en la Matriz de perfiles.'),
                    $this->steps([
                        'Entra a Matriz de perfiles desde la categoría Administración.',
                        'Elige el perfil que necesita el acceso.',
                        'Marca el módulo y el permiso «Ver».',
                        'Pulsa «Aplicar» para guardar los cambios.',
                    ]),
                    $this->p('En la instalación inicial, los módulos Acerca de, Ayuda y FAQ se conceden a los perfiles Administrador y Usuario. Si un perfil no los ve, revisa la matriz.'),
                    $this->note('El bloque de administración depende del rol Administrador, no del acceso al módulo. Un participante con acceso a Ayuda sigue sin ver este bloque.', 'info'),
                ],
                HelpArticle::AUDIENCE_ADMIN
            ),
            $this->seccion(
                'acerca-de',
                'admin-operacion',
                'Operación y mantenimiento',
                'fa-screwdriver-wrench',
                130,
                [
                    $this->p('La administración reúne las tareas de operación del sistema.'),
                    $this->facts([
                        ['label' => 'Eventos', 'value' => 'Crear, iniciar, suspender, posponer, cancelar y reiniciar el sorteo.'],
                        ['label' => 'Participantes', 'value' => 'Inscripción, seguimiento y usuarios asociados.'],
                        ['label' => 'Usuarios', 'value' => 'Crear, editar, bloquear, desbloquear y restablecer contraseñas.'],
                        ['label' => 'Moderación', 'value' => 'Reportes, bloqueos entre usuarios y vetados de grupo.'],
                        ['label' => 'Notificaciones', 'value' => 'Preferencias globales y control de avisos.'],
                        ['label' => 'Ayuda', 'value' => 'Contenido en help_articles y versión en app_settings.'],
                    ]),
                    $this->note('Antes de un cambio grande, revisa el estado del evento y la versión del sistema.', 'info'),
                ],
                HelpArticle::AUDIENCE_ADMIN
            ),
        ];
    }

    /**
     * Contenido de administración de la Guía.
     *
     * Explica cómo mantener el centro de ayuda y solo lo ve el rol
     * Administrador.
     *
     * @return array<int, array<string, mixed>>
     */
    private function adminGuia(): array
    {
        return [
            $this->seccion(
                'ayuda',
                'admin-contenido',
                'Cómo se organiza el contenido',
                'fa-database',
                200,
                [
                    $this->p('El centro de ayuda se guarda en la base de datos. Las vistas no contienen el texto: solo dibujan los bloques que encuentran.'),
                    $this->facts([
                        ['label' => 'help_articles', 'value' => 'Secciones y preguntas. Cada fila tiene módulo, slug, título, bloques y audiencia.'],
                        ['label' => 'audience', 'value' => '«all» para todos; «admin» solo para el rol Administrador.'],
                        ['label' => 'app_settings', 'value' => 'Nombre, lema, versión, estado y notas de la versión.'],
                        ['label' => 'modules / categories', 'value' => 'Ubican las pantallas en el menú y controlan el acceso.'],
                    ]),
                    $this->note('Los artículos de administración se excluyen de la consulta de los participantes. No se ocultan con CSS.', 'important'),
                ],
                HelpArticle::AUDIENCE_ADMIN
            ),
            $this->seccion(
                'ayuda',
                'admin-actualizar',
                'Actualizar el contenido',
                'fa-rotate',
                210,
                [
                    $this->steps([
                        'Edita database/seeders/HelpContentSeeder.php.',
                        'Ejecuta php artisan db:seed --class=HelpContentSeeder --force.',
                        'Revisa las tres pantallas con una cuenta administradora.',
                        'Cierra sesión y revisa con una cuenta de participante para confirmar que el bloque exclusivo no aparece.',
                    ]),
                    $this->p('El seeder actualiza solo las filas que cambiaron y no duplica artículos. En el despliegue, el paso de seed ejecuta este seeder.'),
                    $this->note('Si editas la base directamente, el siguiente seed puede sobrescribir el cambio. Mantén el seeder como fuente de verdad.', 'important'),
                ],
                HelpArticle::AUDIENCE_ADMIN
            ),
            $this->seccion(
                'ayuda',
                'admin-version',
                'Cambiar la versión',
                'fa-code-branch',
                220,
                [
                    $this->steps([
                        'Cambia app.version en app_settings o en HelpContentSeeder.',
                        'Vuelve a ejecutar el seeder si cambiaste el seeder.',
                        'Comprueba que la versión nueva aparece en Acerca de, la guía y las FAQ.',
                    ]),
                    $this->note('La versión se lee de app_settings. No está escrita en las vistas.', 'info'),
                ],
                HelpArticle::AUDIENCE_ADMIN
            ),
            $this->seccion(
                'ayuda',
                'admin-publicar',
                'Publicar, ocultar y crear contenido',
                'fa-eye',
                230,
                [
                    $this->ul([
                        'is_published = false oculta un artículo sin borrarlo.',
                        'audience = «all» lo ve cualquier persona con acceso al módulo.',
                        'audience = «admin» lo ve solo el rol Administrador.',
                        'Para agregar una sección, añade un artículo en el seeder con slug, título, icono, audiencia y bloques.',
                    ]),
                    $this->note('El seeder vuelve a publicar los artículos en cada ejecución. Si necesitas ocultar algo de forma permanente, quítalo del seeder o cambia la consulta.', 'important'),
                ],
                HelpArticle::AUDIENCE_ADMIN
            ),
            $this->seccion(
                'ayuda',
                'admin-contacto',
                'Habilitar la lógica de Contacto y soporte',
                'fa-headset',
                240,
                [
                    $this->p('El módulo ya está activo y visible en el menú. Como todavía no tiene controlador propio, al abrirlo muestra el marcador de posición «Este módulo está en construcción».'),
                    $this->steps([
                        'Conecta su controlador y vista en ModulePages.',
                        'Concede el módulo al perfil Usuario. El Administrador no debe verlo: es un canal para participantes.',
                        'Prueba el formulario o el canal de contacto antes de anunciarlo.',
                        'Actualiza la ayuda pública para explicar el canal disponible.',
                    ]),
                    $this->note('Mientras no tenga controlador, la pantalla no tiene lógica: solo muestra el marcador de posición. Eso es lo esperado en esta etapa.', 'info'),
                ],
                HelpArticle::AUDIENCE_ADMIN
            ),
            $this->seccion(
                'ayuda',
                'admin-buenas-practicas',
                'Buenas prácticas',
                'fa-shield-halved',
                250,
                [
                    $this->ul([
                        'No compartas capturas de la sección de administración con participantes.',
                        'Revisa los accesos después de cambiar un perfil.',
                        'Anota los cambios de versión en el seeder.',
                        'Prueba el contenido con una cuenta de participante antes de desplegar.',
                        'No publiques datos personales ni contraseñas en la ayuda.',
                    ]),
                    $this->note('La separación por rol es una medida de claridad y privacidad. No sustituye las revisiones de seguridad del sistema.', 'info'),
                ],
                HelpArticle::AUDIENCE_ADMIN
            ),
        ];
    }

    /**
     * Preguntas frecuentes exclusivas para el rol Administrador.
     *
     * @return array<int, array<string, mixed>>
     */
    private function adminPreguntasFrecuentes(): array
    {
        $admin = 'Administración de la ayuda';

        return [
            $this->faq('admin-quien-ve', $admin, 710, '¿Quién puede ver las secciones de administración?', [
                $this->p('Solo las cuentas con rol Administrador. El contenido se excluye de la consulta para los participantes; no es solo un ocultamiento visual.'),
            ], HelpArticle::AUDIENCE_ADMIN),
            $this->faq('admin-dar-acceso', $admin, 720, '¿Cómo doy acceso a Ayuda a un perfil?', [
                $this->steps([
                    'Entra a Matriz de perfiles.',
                    'Elige el perfil.',
                    'Marca Acerca de, Ayuda o FAQ y el permiso «Ver».',
                    'Pulsa «Aplicar».',
                ]),
                $this->p('Los tres módulos se conceden al Administrador y al Usuario en la instalación inicial.'),
            ], HelpArticle::AUDIENCE_ADMIN),
            $this->faq('admin-actualizar', $admin, 730, '¿Cómo actualizo el contenido de la ayuda?', [
                $this->p('Edita database/seeders/HelpContentSeeder.php y ejecuta php artisan db:seed --class=HelpContentSeeder --force. En el despliegue, el seeder se ejecuta con el resto de la base.'),
            ], HelpArticle::AUDIENCE_ADMIN),
            $this->faq('admin-version', $admin, 740, '¿Cómo cambio la versión del sistema?', [
                $this->p('Cambia app.version en app_settings o en el seeder y vuelve a sembrar. La versión se muestra en Acerca de, la guía y las FAQ.'),
            ], HelpArticle::AUDIENCE_ADMIN),
            $this->faq('admin-ocultar', $admin, 750, '¿Cómo oculto una pregunta o una sección?', [
                $this->p('Marca is_published = false en el artículo. Ten en cuenta que el seeder vuelve a publicarlo; para ocultarlo de forma permanente, quítalo del seeder o cambia la consulta.'),
            ], HelpArticle::AUDIENCE_ADMIN),
            $this->faq('admin-exclusivo', $admin, 760, '¿Cómo creo contenido solo para administradores?', [
                $this->p('Agrega el artículo con audience = «admin». Aparecerá en la sección Administración y solo lo verá el rol Administrador.'),
            ], HelpArticle::AUDIENCE_ADMIN),
            $this->faq('admin-contacto', $admin, 770, '¿Cómo habilito la lógica de Contacto y soporte?', [
                $this->p('El módulo ya está activo y visible en el menú del perfil Usuario, con el marcador de posición. El Administrador no lo ve. Para habilitar su lógica, conecta su controlador y vista en ModulePages y prueba el canal antes de anunciarlo.'),
            ], HelpArticle::AUDIENCE_ADMIN),
            $this->faq('admin-datos', $admin, 780, '¿Dónde se guarda el contenido?', [
                $this->facts([
                    ['label' => 'help_articles', 'value' => 'Secciones y preguntas, con su audiencia.'],
                    ['label' => 'app_settings', 'value' => 'Nombre, lema y versión del sistema.'],
                    ['label' => 'modules / categories', 'value' => 'Rutas, orden y acceso de las pantallas.'],
                ]),
            ], HelpArticle::AUDIENCE_ADMIN),
            $this->faq('admin-prueba', $admin, 790, '¿Cómo compruebo que un participante no ve la administración?', [
                $this->p('Entra con una cuenta de participante o cierra sesión y vuelve a entrar. Abre las tres pantallas y confirma que no aparece el bloque Administración ni las preguntas exclusivas.'),
            ], HelpArticle::AUDIENCE_ADMIN),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $body
     * @return array<string, mixed>
     */
    private function seccion(string $module, string $slug, string $title, string $icon, int $order, array $body, string $audience = HelpArticle::AUDIENCE_ALL): array
    {
        return [
            'module' => $module,
            'slug' => $slug,
            'title' => $title,
            'kind' => HelpArticle::KIND_SECTION,
            'audience' => $audience,
            'topic' => null,
            'icon' => $icon,
            'sort_order' => $order,
            'body' => $body,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $body
     * @return array<string, mixed>
     */
    private function faq(string $slug, string $topic, int $order, string $question, array $body, string $audience = HelpArticle::AUDIENCE_ALL): array
    {
        return [
            'module' => 'faq',
            'slug' => $slug,
            'title' => $question,
            'kind' => HelpArticle::KIND_FAQ,
            'audience' => $audience,
            'topic' => $topic,
            'icon' => null,
            'sort_order' => $order,
            'body' => $body,
        ];
    }

    /**
     * @return array{type: string, text: string}
     */
    private function p(string $text): array
    {
        return ['type' => 'paragraph', 'text' => $text];
    }

    /**
     * @param  array<int, string>  $items
     * @return array{type: string, items: array<int, string>}
     */
    private function ul(array $items): array
    {
        return ['type' => 'list', 'items' => $items];
    }

    /**
     * @param  array<int, string|array{title: string, text: string}>  $items
     * @return array{type: string, items: array<int, string|array{title: string, text: string}>}
     */
    private function steps(array $items): array
    {
        return ['type' => 'steps', 'items' => $items];
    }

    /**
     * @return array{type: string, tone: string, title: string|null, text: string}
     */
    private function note(string $text, string $tone = 'info', ?string $title = null): array
    {
        return [
            'type' => 'note',
            'tone' => $tone,
            'title' => $title,
            'text' => $text,
        ];
    }

    /**
     * @param  array<int, array{label: string, value: string}>  $items
     * @return array{type: string, items: array<int, array{label: string, value: string}>}
     */
    private function facts(array $items): array
    {
        return ['type' => 'facts', 'items' => $items];
    }

    /**
     * @return array{type: string}
     */
    private function versionBlock(): array
    {
        return ['type' => 'version'];
    }
}
