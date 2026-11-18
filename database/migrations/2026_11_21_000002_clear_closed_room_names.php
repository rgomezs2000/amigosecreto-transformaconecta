<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Las salas ya no tienen nombre: se identifican únicamente como «Sala».
     * Se limpian los nombres que hubieran quedado de altas anteriores.
     */
    public function up(): void
    {
        DB::table('chat_conversations')
            ->where('is_closed', true)
            ->update(['name' => null]);
    }

    public function down(): void
    {
        // No se puede reconstruir un nombre que no existía.
    }
};
