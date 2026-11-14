<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un mensaje del chat puede adjuntar un evento del sistema: la burbuja
     * muestra su resumen y el detalle abre la ficha del evento.
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('type')
                ->constrained('events')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
        });
    }
};
