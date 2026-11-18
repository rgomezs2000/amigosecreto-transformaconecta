<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Las solicitudes de cuenta (restablecer contraseña o desbloquear) se
     * resuelven con las acciones de su ficha, no con un chat.
     */
    public function up(): void
    {
        $reports = DB::table('chat_reports')
            ->where('type', 'cuenta')
            ->pluck('id');

        if ($reports->isEmpty()) {
            return;
        }

        DB::table('chat_conversations')
            ->whereIn('report_id', $reports)
            ->delete();
    }

    public function down(): void
    {
        // No se recrean chats de solicitudes de cuenta.
    }
};
