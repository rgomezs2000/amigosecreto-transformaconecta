<?php

namespace App\Console\Commands;

use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Clue;
use App\Models\ConsumableGift;
use App\Models\Event;
use App\Models\EventParticipant;
use App\Models\ExchangeGift;
use App\Models\Wishlist;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reinicia por completo el evento de un año.
 *
 * Borra el evento y toda su dinámica: participaciones, listas de deseos,
 * asignaciones, pistas, regalos y auditoría. No toca a los usuarios del
 * sistema ni los chats generales.
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

        foreach ($events as $event) {
            try {
                DB::transaction(function () use ($event, $logger) {
                    $assignmentIds = Assignment::query()
                        ->where('event_id', $event->id)
                        ->pluck('id');

                    $participationIds = EventParticipant::query()
                        ->where('event_id', $event->id)
                        ->pluck('id');

                    $logger->info('Eliminando dinámica del evento', [
                        'event_id' => $event->id,
                        'assignments' => $assignmentIds->count(),
                        'participations' => $participationIds->count(),
                    ]);

                    Clue::query()->whereIn('assignment_id', $assignmentIds)->delete();
                    ExchangeGift::query()->whereIn('assignment_id', $assignmentIds)->delete();
                    ConsumableGift::query()->whereIn('assignment_id', $assignmentIds)->delete();
                    Assignment::query()->whereIn('id', $assignmentIds)->delete();

                    Wishlist::query()->whereIn('event_participant_id', $participationIds)->delete();
                    EventParticipant::query()->whereIn('id', $participationIds)->delete();

                    AuditLog::query()->where('event_id', $event->id)->delete();

                    $secretConversations = \App\Models\Conversation::query()
                        ->where('event_id', $event->id)
                        ->pluck('id');

                    if ($secretConversations->isNotEmpty()) {
                        \App\Models\ChatMessage::query()
                            ->whereIn('conversation_id', $secretConversations)
                            ->delete();

                        DB::table('chat_conversation_user')
                            ->whereIn('conversation_id', $secretConversations)
                            ->delete();

                        \App\Models\Conversation::query()
                            ->whereIn('id', $secretConversations)
                            ->delete();

                        $logger->info('Conversaciones del amigo secreto eliminadas', [
                            'count' => $secretConversations->count(),
                        ]);
                    }

                    $event->delete();

                    $logger->info('Evento eliminado por completo', ['event_id' => $event->id]);
                });

                $this->info("Evento #{$event->id} eliminado.");
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
}
