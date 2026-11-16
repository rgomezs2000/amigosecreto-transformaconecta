<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos de una solicitud pública (prerregistro o recuperación de cuenta)
     * que el administrador necesita para gestionar el caso.
     */
    public function up(): void
    {
        Schema::table('chat_reports', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('suggestion');
        });
    }

    public function down(): void
    {
        Schema::table('chat_reports', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
    }
};
