<?php

namespace App\Console\Commands;

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\ChatMessage;
use App\Models\Clue;
use App\Models\ConsumableGift;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\ExchangeGift;
use App\Models\WishItem;
use App\Models\Wishlist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reinicia por completo el evento de un año.
 *
 * Borra el evento y toda su dinámica: invitaciones/participaciones, listas de
 * deseos, asignaciones, pistas, regalos, chats del evento y auditoría. No toca
 * a los usuarios del sistema, los reportes de moderación ni los chats
 * generales.
 *
 * Es un proceso distinto al de «dinamica:reiniciar»: aquel deja el sistema en
 * cero (incluidos usuarios, reportes y conversaciones); este solo limpia el
 * flujo principal de un evento.
 */
class ReiniciarEvento extends Command
{
    protected $signature = 'evento:reiniciar {year : Año del evento a reiniciar}';

    protected $description = 'Elimina el evento del año y toda su dinámica, sin borrar usuarios.';

    public function handle(): int
    {
        $year = (int) $this->argument('year');
        $logger = Log::channel('eventos');

        $logger->info('Inicio del reinicio del evento', ['year' => $year]);
        $this->info("Reiniciando evento del año {$year}...");

        $events = Event::query()->where('year', $year)->get();

        if ($events->isEmpty()) {
            $logger->warning('No hay evento para reiniciar', ['year' => $year]);
            $this->warn("No hay ningún evento en el año {$year}.");

            return self::SUCCESS;
        }

        if ($events->contains(fn (Event $event) => $event->status->isFinished())) {
            $logger->warning('El evento ya se celebró; no se reinicia', ['year' => $year]);
            $this->error('El evento ya se celebró y quedó finalizado: no se puede reiniciar.');

            return self::FAILURE;
        }

        foreach ($events as $event) {
            $archivos = [];

            try {
                DB::transaction(function () use ($event, $logger, &$archivos) {
                    $assignmentIds = Assignment::query()
                        ->where('event_id', $event->id)
                        ->pluck('id');

                    $participationIds = EventParticipant::query()
                        ->where('event_id', $event->id)
                        ->pluck('id');

                    $conversationIds = Conversation::query()
                        ->where('event_id', $event->id)
                        ->pluck('id');

                    // Mensajes de los chats del evento y mensajes que adjuntan
                    // la ficha del evento desde cualquier conversación.
                    $messageIds = ChatMessage::query()
                        ->where(function ($query) use ($event, $conversationIds) {
                            $query->where('event_id', $event->id);

                            if ($conversationIds->isNotEmpty()) {
                                $query->orWhereIn('conversation_id', $conversationIds);
                            }
                        })
                        ->pluck('id');

                    $logger->info('Eliminando dinámica del evento', [
                        'event_id' => $event->id,
                        'assignments' => $assignmentIds->count(),
                        'participations' => $participationIds->count(),
                        'conversations' => $conversationIds->count(),
                        'messages' => $messageIds->count(),
                    ]);

                    if ($messageIds->isNotEmpty()) {
                        $archivos = DB::table('chat_message_attachments')
                            ->whereIn('message_id', $messageIds)
                            ->pluck('path')
                            ->filter()
                            ->values()
                            ->all();

                        // Los reportes de moderación no forman parte del flujo
                        // del evento: se conservan, sin el mensaje que ya no
                        // existirá.
                        DB::table('chat_reports')
                            ->whereIn('message_id', $messageIds)
                            ->update(['message_id' => null]);

                        DB::table('chat_message_attachments')->whereIn('message_id', $messageIds)->delete();
                        DB::table('chat_message_favorites')->whereIn('message_id', $messageIds)->delete();
                        DB::table('chat_message_reactions')->whereIn('message_id', $messageIds)->delete();

                        $pollIds = DB::table('chat_polls')
                            ->where(function ($query) use ($messageIds, $conversationIds) {
                                $query->whereIn('message_id', $messageIds);

                                if ($conversationIds->isNotEmpty()) {
                                    $query->orWhereIn('conversation_id', $conversationIds);
                                }
                            })
                            ->pluck('id');

                        if ($pollIds->isNotEmpty()) {
                            DB::table('chat_poll_votes')->whereIn('poll_id', $pollIds)->delete();
                            DB::table('chat_poll_options')->whereIn('poll_id', $pollIds)->delete();
                            DB::table('chat_polls')->whereIn('id', $pollIds)->delete();
                        }

                        ChatMessage::query()->whereIn('id', $messageIds)->delete();
                    }

                    if ($conversationIds->isNotEmpty()) {
                        DB::table('chat_broadcast_recipients')->whereIn('conversation_id', $conversationIds)->delete();
                        DB::table('chat_moderation_reads')->whereIn('conversation_id', $conversationIds)->delete();
                        DB::table('chat_group_audits')->whereIn('conversation_id', $conversationIds)->delete();
                        DB::table('chat_group_bans')->whereIn('conversation_id', $conversationIds)->delete();
                        DB::table('chat_group_suspensions')->whereIn('conversation_id', $conversationIds)->delete();
                        DB::table('chat_group_invitations')->whereIn('conversation_id', $conversationIds)->delete();
                        DB::table('chat_conversation_user')->whereIn('conversation_id', $conversationIds)->delete();

                        Conversation::query()->whereIn('id', $conversationIds)->delete();

                        $logger->info('Conversaciones del evento eliminadas', [
                            'count' => $conversationIds->count(),
                        ]);
                    }

                    Clue::query()->whereIn('assignment_id', $assignmentIds)->delete();
                    ExchangeGift::query()->whereIn('assignment_id', $assignmentIds)->delete();
                    ConsumableGift::query()->whereIn('assignment_id', $assignmentIds)->delete();

                    Wishlist::query()->whereIn('event_participant_id', $participationIds)->delete();
                    WishItem::query()->where('event_id', $event->id)->delete();

                    Assignment::query()->whereIn('id', $assignmentIds)->delete();
                    EventParticipant::query()->whereIn('id', $participationIds)->delete();

                    // Avisos del evento: no deben quedar apuntando a un evento,
                    // conversación o mensaje que ya no existe.
                    DB::table('notifications')
                        ->where(function ($query) use ($event, $conversationIds, $messageIds) {
                            $query->whereRaw(
                                "JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.event_id')) = ?",
                                [(string) $event->id]
                            );

                            foreach ($conversationIds as $conversationId) {
                                $query->orWhereRaw(
                                    "JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.conversation_id')) = ?",
                                    [(string) $conversationId]
                                );
                            }

                            foreach ($messageIds as $messageId) {
                                $query->orWhereRaw(
                                    "JSON_UNQUOTE(JSON_EXTRACT(`data`, '$.message_id')) = ?",
                                    [(string) $messageId]
                                );
                            }
                        })
                        ->delete();

                    AuditLog::query()->where('event_id', $event->id)->delete();

                    $event->delete();

                    $logger->info('Evento eliminado por completo', ['event_id' => $event->id]);
                });

                $files = $this->purgeAttachmentFiles($archivos);

                $this->info("Evento #{$event->id} eliminado.");
                $this->line('  Adjuntos del evento eliminados del disco: '.$files);
            } catch (Throwable $exception) {
                $logger->error('Falló el reinicio del evento', [
                    'event_id' => $event->id,
                    'error' => $exception->getMessage(),
                ]);

                $this->error("Falló el evento #{$event->id}: {$exception->getMessage()}");

                return self::FAILURE;
            }
        }

        $logger->info('Reinicio del evento completado', ['year' => $year]);
        $this->info('Reinicio completado. Ya puedes crear un evento nuevo.');

        return self::SUCCESS;
    }

    /**
     * Elimina del disco los adjuntos de los mensajes del evento.
     *
     * @param  array<int, string>  $paths
     */
    private function purgeAttachmentFiles(array $paths): int
    {
        $eliminados = 0;

        foreach (array_unique($paths) as $path) {
            try {
                if ($path !== '' && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                    $eliminados++;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $eliminados;
    }
}
