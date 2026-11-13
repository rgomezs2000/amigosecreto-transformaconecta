<?php

namespace App\Services;

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
     */
    public function abrirTicket(ChatReport $report): Conversation
    {
        $conversation = $this->buscar($report);
        $created = false;

        if ($conversation === null) {
            $conversation = Conversation::create([
                'name' => 'Reporte Nº: '.$report->id,
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
     */
    public function gestionar(ChatReport $report, User $admin): Conversation
    {
        $conversation = $this->abrirTicket($report);

        $this->agregarParticipante($conversation, $admin->id);
        $this->estado($conversation, false);

        return $conversation;
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

        // Un caso en atención siempre admite escritura para el gestor.
        if ($conversation->is_read_only) {
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
            'body' => 'Reporte Nº: '.$report->id,
            'type' => 'text',
            'is_moderation' => false,
            'delivered_at' => now(),
        ]);

        $this->firestore->recordMessage(
            $message->load('user', 'attachments', 'conversation')
        );
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
