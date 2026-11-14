<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Firestore\FirestoreService;

/**
 * Chat de un caso de reporte (ticket) y sus fases.
 *
 * - Al abrir el ticket nace el chat, en **solo lectura** y solo con el
 *   beneficiario (quien reportó). Ningún administrador está dentro todavía.
 * - Al **tomar** el caso entra el administrador que lo gestiona y el chat pasa
 *   a escritura completa para los dos.
 * - Al **cerrar** el caso el chat vuelve a solo lectura para ambos.
 */
class ReporteChat
{
    public function __construct(private readonly FirestoreService $firestore) {}

    /**
     * Crea (o reutiliza) el chat del ticket al abrir el caso.
     *
     * Las sugerencias no abren chat para el usuario: se crea cuando el
     * administrador toma el caso, y el usuario entra en solo lectura cuando
     * recibe la primera respuesta.
     */
    public function abrirTicket(ChatReport $report): ?Conversation
    {
        if ($report->isSuggestion()) {
            return null;
        }

        $conversation = $this->buscar($report);
        $created = false;

        if ($conversation === null) {
            $conversation = Conversation::create([
                'name' => $this->nombreTicket($report),
                'type' => 'report',
                'report_id' => $report->id,
                'created_by' => $report->reporter_id,
                'is_moderated' => false,
                'is_read_only' => true,
            ]);

            $created = true;
        }

        $this->agregarParticipante($conversation, $report->reporter_id);
        $this->mensajeInicial($conversation, $report);
        $this->estado($conversation, true);

        if ($created) {
            $this->firestore->recordConversation($conversation->fresh('users'));
        }

        return $conversation;
    }

    /**
     * El administrador toma el caso: entra al chat y se habilita la escritura.
     *
     * En una sugerencia el chat nace en solo lectura y solo con el
     * administrador; la respuesta se envía desde la pestaña Sugerencia y el
     * usuario se suma al recibirla.
     */
    public function gestionar(ChatReport $report, User $admin): Conversation
    {
        if ($report->isSuggestion()) {
            $conversation = $this->buscar($report);

            if ($conversation === null) {
                $conversation = Conversation::create([
                    'name' => $this->nombreTicket($report),
                    'type' => 'report',
                    'report_id' => $report->id,
                    'created_by' => $report->reporter_id,
                    'is_moderated' => false,
                    'is_read_only' => true,
                ]);

                $this->firestore->recordConversation($conversation->fresh('users'));
            }

            $this->agregarParticipante($conversation, $admin->id);
            $this->mensajeInicial($conversation, $report);
            $this->estado($conversation, true);

            return $conversation;
        }

        $conversation = $this->abrirTicket($report);

        if ($conversation === null) {
            $conversation = Conversation::create([
                'name' => $this->nombreTicket($report),
                'type' => 'report',
                'report_id' => $report->id,
                'created_by' => $report->reporter_id,
                'is_moderated' => false,
                'is_read_only' => false,
            ]);
        }

        $this->agregarParticipante($conversation, $admin->id);
        $this->estado($conversation, false);

        return $conversation;
    }

    /**
     * Respuesta del administrador a una sugerencia.
     *
     * El mensaje se escribe aunque el chat esté en solo lectura (es el canal
     * de respuesta de la pestaña Sugerencia). Al enviarlo, el usuario entra al
     * chat en solo lectura para que pueda leer la respuesta.
     */
    public function responderSugerencia(ChatReport $report, User $admin, string $body): ChatMessage
    {
        $conversation = $this->gestionar($report, $admin);
        $this->agregarParticipante($conversation, $report->reporter_id);

        $message = $conversation->messages()->create([
            'user_id' => $admin->id,
            'body' => $body,
            'type' => 'text',
            'is_moderation' => false,
            'delivered_at' => now(),
        ]);

        $this->firestore->recordMessage(
            $message->load('user', 'attachments', 'conversation')
        );

        return $message;
    }

    /**
     * Caso cerrado: el chat queda en solo lectura para ambos.
     */
    public function cerrar(ChatReport $report): ?Conversation
    {
        $conversation = $this->buscar($report);

        if ($conversation === null) {
            return null;
        }

        $this->estado($conversation, true);

        return $conversation;
    }

    /**
     * El caso cambia de gestor: el chat pasa al nuevo administrador y el
     * anterior sale (salvo que sea el beneficiario).
     */
    public function trasladar(ChatReport $report, User $from, User $to): ?Conversation
    {
        $conversation = $this->buscar($report);

        if ($conversation === null) {
            return null;
        }

        if ((int) $report->reporter_id !== (int) $from->id) {
            $conversation->users()->detach($from->id);
        }

        $conversation->users()->syncWithoutDetaching([(int) $to->id]);

        // Un caso en atención siempre admite escritura para el gestor, salvo
        // la sugerencia: su respuesta va por la pestaña Sugerencia.
        if (! $report->isSuggestion() && $conversation->is_read_only) {
            $conversation->forceFill(['is_read_only' => false])->save();
        }

        $this->firestore->recordConversation($conversation->fresh('users'));

        return $conversation;
    }

    private function buscar(ChatReport $report): ?Conversation
    {
        return Conversation::query()->where('report_id', $report->id)->first();
    }

    private function agregarParticipante(Conversation $conversation, ?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $conversation->users()->syncWithoutDetaching([(int) $userId]);
    }

    /**
     * Primer mensaje del chat: identifica el ticket.
     */
    private function mensajeInicial(Conversation $conversation, ChatReport $report): void
    {
        if ($conversation->messages()->exists()) {
            return;
        }

        $message = $conversation->messages()->create([
            'user_id' => null,
            'body' => $this->nombreTicket($report),
            'type' => 'text',
            'is_moderation' => false,
            'delivered_at' => now(),
        ]);

        $this->firestore->recordMessage(
            $message->load('user', 'attachments', 'conversation')
        );
    }

    private function nombreTicket(ChatReport $report): string
    {
        return ($report->isSuggestion() ? 'Sugerencia Nº: ' : 'Reporte Nº: ').$report->id;
    }

    /**
     * Guarda el estado de escritura y lo espeja en Firestore.
     */
    private function estado(Conversation $conversation, bool $readOnly): void
    {
        if ((bool) $conversation->is_read_only === $readOnly) {
            return;
        }

        $conversation->forceFill(['is_read_only' => $readOnly])->save();
        $this->firestore->recordConversation($conversation->fresh('users'));
    }
}
