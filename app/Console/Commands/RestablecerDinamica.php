<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DinamicaProgress;
use App\Services\SesionesReset;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Restablece por completo la dinámica del amigo secreto.
 *
 * Es un proceso distinto al de «evento:reiniciar»: aquel borra un evento de un
 * año y deja usuarios y chats generales; este deja el sistema en cero.
 *
 * Se conserva lo esencial:
 *   - los CRUD: perfiles (roles), permisos, categorías, módulos y la matriz
 *     de accesos;
 *   - la configuración de notificaciones;
 *   - la cuenta del superadministrador, que es intocable.
 *
 * Se elimina todo lo demás: eventos, participaciones, listas de deseos,
 * asignaciones, pistas, regalos, conversaciones, mensajes, adjuntos,
 * moderación, reportes, bloqueos, invitaciones, notificaciones, auditoría,
 * correos registrados y las cuentas de los demás usuarios.
 *
 * Antes de borrar a los usuarios se cierran sus sesiones con un aviso, y
 * después de confirmar el borrado se les envía un correo avisando del
 * restablecimiento y pidiéndoles que soliciten un nuevo usuario.
 */
class RestablecerDinamica extends Command
{
    /** Aviso que verá cada usuario desconectado en la pantalla de acceso. */
    public const AVISO_SESION = 'La administración restableció el sistema del amigo secreto: tu sesión se cerró y tu cuenta fue eliminada. Solicita un nuevo usuario a la administración.';

    protected $signature = 'dinamica:reiniciar
        {--run= : Identificador de la ejecución (progreso y bitácora)}
        {--force : Ejecuta sin pedir confirmación en consola}';

    protected $description = 'Deja el sistema en cero: elimina toda la dinámica del amigo secreto y conserva los CRUD, los módulos, los roles y el superadministrador.';

    private ?SesionesReset $sesiones = null;

    public function handle(DinamicaProgress $progress, SesionesReset $sesiones): int
    {
        $this->sesiones = $sesiones;

        $run = trim((string) ($this->option('run') ?: Str::uuid()));

        if (! $progress->isValidRun($run)) {
            $this->error('El identificador de la ejecución no es válido.');

            return self::FAILURE;
        }

        $progress->start($run);

        $superAdmin = User::superAdmin();

        // Sin superadministrador no se borra nada: se perdería el acceso.
        if ($superAdmin === null) {
            $progress->finish(
                $run,
                false,
                'No se encontró al superadministrador. No se eliminó nada.'
            );

            $this->error('No se encontró al superadministrador. No se eliminó nada.');

            return self::FAILURE;
        }

        if (! $this->option('force') && $this->input->isInteractive()) {
            $this->warn('Se eliminará toda la dinámica: evento, chats, adjuntos, moderación, reportes y todos los usuarios excepto '.$superAdmin->username.'.');

            if (! $this->confirm('¿Restablecer la dinámica del amigo secreto?', false)) {
                $progress->cancel($run);

                $this->info('Proceso cancelado.');

                return self::SUCCESS;
            }
        }

        // A quiénes hay que avisarles por correo: se toma la lista antes de
        // borrar, porque después los usuarios ya no existen.
        $avisos = $this->usuariosAEliminar($superAdmin->id);

        $steps = $this->steps($superAdmin->id);
        $total = count($steps) + 3; // + archivos del chat + archivos de casos + correos de aviso.

        $progress->note($run, 'Inicio del restablecimiento de la dinámica.', 'info');
        $progress->note($run, 'Se conserva la cuenta '.$superAdmin->username.' (#'.$superAdmin->id.').', 'info');
        $progress->note($run, 'Se avisará por correo a '.$avisos->count().' cuenta(s) que serán eliminadas.', 'info');

        $this->info('Restableciendo la dinámica del amigo secreto...');

        try {
            DB::transaction(function () use ($steps, $total, $run, $progress, $superAdmin): void {
                $index = 0;

                foreach ($steps as $label => $callback) {
                    $index++;
                    $rows = (int) $callback();

                    $progress->step($run, $index, $total, $label, $rows);
                    $this->line('  '.$index.'/'.$total.' '.$label.' ('.$rows.')');
                }

                // Salvaguarda: el superadministrador sigue ahí.
                if (! User::query()->whereKey($superAdmin->id)->exists()) {
                    throw new \RuntimeException('El superadministrador desapareció durante el proceso.');
                }
            });
        } catch (Throwable $exception) {
            $progress->finish(
                $run,
                false,
                'El restablecimiento falló. No se eliminó nada.',
                $exception->getMessage()
            );

            $this->error('Falló el restablecimiento: '.$exception->getMessage());

            return self::FAILURE;
        }

        // Los adjuntos viven en el disco: se borran después de confirmar la
        // transacción para no dejar archivos de mensajes que ya no existen.
        $files = $this->purgeAttachmentFiles();

        $progress->step($run, $total - 2, $total, 'Archivos de los adjuntos del chat', $files);

        $reportFiles = $this->purgeReportFiles();

        $progress->step($run, $total - 1, $total, 'Archivos de casos de ayuda y soporte', $reportFiles);

        // Avisos por correo: solo si el borrado ya está confirmado.
        $enviados = $this->enviarAvisos($run, $progress, $avisos);

        $progress->step($run, $total, $total, 'Avisos por correo a los usuarios eliminados', $enviados);

        $progress->finish(
            $run,
            true,
            'Dinámica restablecida. El sistema quedó en cero; el superadministrador se mantiene.'
        );

        $this->info('Dinámica restablecida. Ya puedes crear un evento nuevo.');

        return self::SUCCESS;
    }

    /**
     * Pasos del borrado, en orden de dependencias (hijos antes que padres).
     *
     * @return array<string, callable(): int>
     */
    private function steps(int $superAdminId): array
    {
        return [
            'Adjuntos del chat' => fn (): int => DB::table('chat_message_attachments')->delete(),
            'Reacciones del chat' => fn (): int => DB::table('chat_message_reactions')->delete(),
            'Adjuntos de casos' => fn (): int => DB::table('chat_report_attachments')->delete(),
            'Enlaces de casos' => fn (): int => DB::table('chat_report_links')->delete(),
            'Reportes de moderación' => fn (): int => DB::table('chat_reports')->delete(),
            'Lecturas de moderación' => fn (): int => DB::table('chat_moderation_reads')->delete(),
            'Bitácora de grupos' => fn (): int => DB::table('chat_group_audits')->delete(),
            'Vetados de grupo' => fn (): int => DB::table('chat_group_bans')->delete(),
            'Suspensiones de grupo' => fn (): int => DB::table('chat_group_suspensions')->delete(),
            'Invitaciones de grupo' => fn (): int => DB::table('chat_group_invitations')->delete(),
            'Bloqueos entre usuarios' => fn (): int => DB::table('chat_blocks')->delete(),
            'Mensajes del chat' => fn (): int => DB::table('chat_messages')->delete(),
            'Participantes de conversaciones' => fn (): int => DB::table('chat_conversation_user')->delete(),
            'Conversaciones' => fn (): int => DB::table('chat_conversations')->delete(),
            'Pistas' => fn (): int => DB::table('clues')->delete(),
            'Regalos de intercambio' => fn (): int => DB::table('exchange_gifts')->delete(),
            'Regalos consumibles' => fn (): int => DB::table('consumable_gifts')->delete(),
            'Listas de deseos' => fn (): int => DB::table('wishlists')->delete(),
            'Asignaciones' => fn (): int => DB::table('assignments')->delete(),
            'Participaciones del evento' => fn (): int => DB::table('event_participants')->delete(),
            'Auditoría' => fn (): int => DB::table('audit_logs')->delete(),
            'Eventos' => fn (): int => DB::table('events')->delete(),
            'Notificaciones' => fn (): int => DB::table('notifications')->delete(),
            'Correos registrados' => fn (): int => DB::table('email_logs')->delete(),
            'Preferencias de notificación' => fn (): int => DB::table('notification_preferences')
                ->where('user_id', '!=', $superAdminId)
                ->delete(),
            'Cuentas de Google' => fn (): int => DB::table('social_accounts')
                ->where('user_id', '!=', $superAdminId)
                ->delete(),
            // Los permisos individuales del superadministrador definen su
            // acceso al módulo de restablecer: no se tocan.
            'Permisos individuales' => fn (): int => DB::table('user_permission_module')
                ->where('user_id', '!=', $superAdminId)
                ->delete(),
            // Primero se cierran las sesiones (dejando el aviso) y después se
            // borran las cuentas: así nadie queda con la sesión abierta.
            'Sesiones de los usuarios' => fn (): int => $this->cerrarSesiones($superAdminId),
            'Usuarios' => fn (): int => DB::table('users')->where('id', '!=', $superAdminId)->delete(),
            'Tokens de recuperación' => fn (): int => DB::table('password_reset_tokens')->delete(),
        ];
    }

    /**
     * Cierra las sesiones de todos los usuarios que serán eliminados.
     */
    private function cerrarSesiones(int $superAdminId): int
    {
        if ($this->sesiones === null) {
            return 0;
        }

        // Los invitados no tienen a quién avisar: se borran de una vez.
        $cerradas = $this->sesiones->limpiarInvitadas();

        $ids = DB::table('sessions')
            ->whereNotNull('user_id')
            ->where('user_id', '!=', $superAdminId)
            ->distinct()
            ->pluck('user_id');

        foreach ($ids as $id) {
            $cerradas += $this->sesiones->desconectar((int) $id, self::AVISO_SESION);
        }

        return $cerradas;
    }

    /**
     * Cuentas que se van a eliminar y tienen un correo válido.
     *
     * @return Collection<int, object>
     */
    private function usuariosAEliminar(int $superAdminId): Collection
    {
        return DB::table('users')
            ->where('id', '!=', $superAdminId)
            ->select('id', 'name', 'email')
            ->get()
            ->filter(fn ($usuario) => filter_var(trim((string) $usuario->email), FILTER_VALIDATE_EMAIL) !== false)
            ->values();
    }

    /**
     * Envía el aviso del restablecimiento a las cuentas eliminadas.
     *
     * Un fallo de correo no tumba el proceso: el borrado ya está confirmado.
     *
     * @param  Collection<int, object>  $avisos
     */
    private function enviarAvisos(string $run, DinamicaProgress $progress, Collection $avisos): int
    {
        $enviados = 0;
        $fallidos = 0;

        foreach ($avisos as $usuario) {
            try {
                $ok = send_mail(
                    (string) $usuario->email,
                    'El sistema será restablecido',
                    'Tu cuenta fue eliminada',
                    [
                        'Hola, '.$usuario->name.'.',
                        'La administración restableció el sistema del amigo secreto: se eliminaron el evento, las listas, los chats y las cuentas de los participantes.',
                        'Tu cuenta fue eliminada como parte del restablecimiento y tu sesión se cerró.',
                        'Para volver a participar, ponte en contacto con la administración y solicita un nuevo usuario.',
                    ],
                    null,
                    [
                        'recipient_name' => (string) $usuario->name,
                        'eyebrow' => 'Restablecimiento',
                        'preheader' => 'El sistema será restablecido.',
                        'source' => 'dinamica',
                    ]
                );

                if ($ok) {
                    $enviados++;
                } else {
                    $fallidos++;
                }
            } catch (Throwable $exception) {
                $fallidos++;
                report($exception);
            }
        }

        if ($fallidos > 0) {
            $progress->note(
                $run,
                'Avisos por correo: '.$enviados.' enviado(s), '.$fallidos.' con error.',
                'warning'
            );
        }

        return $enviados;
    }

    /**
     * Elimina los archivos de los adjuntos del chat del disco público.
     */
    private function purgeAttachmentFiles(): int
    {
        try {
            $disk = Storage::disk('public');

            if (! $disk->exists('chat')) {
                return 0;
            }

            $files = count($disk->allFiles('chat'));

            $disk->deleteDirectory('chat');

            return $files;
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }

    /**
     * Elimina los archivos de los casos de ayuda y soporte.
     */
    private function purgeReportFiles(): int
    {
        try {
            $disk = Storage::disk('local');

            if (! $disk->exists('reportes')) {
                return 0;
            }

            $files = count($disk->allFiles('reportes'));

            $disk->deleteDirectory('reportes');

            return $files;
        } catch (Throwable $exception) {
            report($exception);

            return 0;
        }
    }
}
