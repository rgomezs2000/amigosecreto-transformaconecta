<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\ChatReport;
use App\Models\Conversation;
use App\Services\Firestore\FirestoreService;
use Illuminate\Console\Command;

/**
 * Copia el historial existente del chat a Firestore.
 *
 * Sirve para la carga inicial o para recuperar documentos que no se hayan
 * sincronizado mientras Firebase estuvo desactivado.
 */
class SyncChatFirestore extends Command
{
    protected $signature = 'chat:sync-firestore {--limit=1000 : Máximo de registros por colección}';

    protected $description = 'Sincroniza conversaciones, mensajes y reportes del chat con Firestore';

    public function handle(FirestoreService $firestore): int
    {
        if (! $firestore->enabled()) {
            $this->warn('Firestore está desactivado. Revisa FIREBASE_CREDENTIALS y FIREBASE_FIRESTORE_DRIVER.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));

        $conversations = Conversation::query()
            ->with('users')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $conversationCount = $firestore->recordConversations($conversations);

        $messages = ChatMessage::query()
            ->with(['user', 'attachments', 'conversation'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $messageCount = $firestore->recordMessages($messages);

        $reports = ChatReport::query()
            ->with(['message.conversation.users', 'message.user', 'reporter'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $reportCount = $firestore->recordReports($reports);

        $this->table(['Colección', 'Documentos'], [
            ['conversaciones', $conversationCount],
            ['mensajes_chat', $messageCount],
            ['reportes_chat', $reportCount],
        ]);

        return self::SUCCESS;
    }
}
