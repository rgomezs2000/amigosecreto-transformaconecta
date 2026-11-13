<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un chat por cada reporte (ticket).
 *
 * - `chat_conversations.report_id`: enlaza el chat con su reporte. Un reporte
 *   pendiente todavía no tiene chat: se crea cuando un administrador lo toma.
 * - Los reportes ya tomados o cerrados se convierten aquí en su chat del caso,
 *   con el mensaje «Reporte Nº: X», para que no se queden fuera de la pestaña.
 * - El canal compartido «Reportes» desaparece: cada caso tiene su propio chat,
 *   visible solo para el beneficiario y el administrador asignado.
 *
 * El chat queda en solo lectura cuando el caso se cierra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->foreignId('report_id')
                ->nullable()
                ->after('assignment_id')
                ->unique()
                ->constrained('chat_reports')
                ->nullOnDelete();
        });

        $now = now();

        foreach (DB::table('chat_reports')->get() as $report) {
            $adminId = $report->assigned_to ?: $report->closed_by;

            $exists = DB::table('chat_conversations')->where('report_id', $report->id)->exists();

            if ($exists) {
                continue;
            }

            // Cada caso tiene su chat desde que se abre: en solo lectura y con
            // el beneficiario. El administrador se suma al tomarlo.
            $conversationId = DB::table('chat_conversations')->insertGetId([
                'name' => 'Reporte Nº: '.$report->id,
                'type' => 'report',
                'created_by' => $adminId ?: $report->reporter_id,
                'report_id' => $report->id,
                'is_moderated' => false,
                'is_read_only' => $report->status !== 'atendiendo',
                'created_at' => $report->created_at ?? $now,
                'updated_at' => $now,
            ]);

            $members = array_values(array_unique(array_filter([
                $report->reporter_id,
                $adminId,
            ])));

            foreach ($members as $memberId) {
                DB::table('chat_conversation_user')->insert([
                    'conversation_id' => $conversationId,
                    'user_id' => $memberId,
                    'role' => 'member',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('chat_messages')->insert([
                'conversation_id' => $conversationId,
                'user_id' => null,
                'body' => 'Reporte Nº: '.$report->id,
                'type' => 'text',
                'is_moderation' => false,
                'delivered_at' => $now,
                'created_at' => $report->created_at ?? $now,
                'updated_at' => $now,
            ]);
        }

        // El canal único de reportes se retira: su contenido queda en los chats
        // de cada ticket.
        DB::table('chat_conversations')->where('type', 'reports')->delete();
    }

    public function down(): void
    {
        DB::table('chat_conversations')->where('type', 'report')->delete();

        // Primero la clave foránea (usa el índice único de report_id) y luego
        // la columna, que se lleva su índice.
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropForeign(['report_id']);
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropColumn('report_id');
        });
    }
};
