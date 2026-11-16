<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Los casos de prerregistro no deben tener chat: el administrador
     * formaliza el alta desde el módulo de Usuarios. Se eliminan los chats
     * que se hayan creado antes de este cambio.
     */
    public function up(): void
    {
        $reports = DB::table('chat_reports')
            ->where('type', 'registro')
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
        // No se recrean chats de casos de registro.
    }
};
