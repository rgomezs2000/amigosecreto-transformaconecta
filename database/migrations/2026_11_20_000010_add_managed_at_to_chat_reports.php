<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca el momento en que un caso tuvo una gestión efectiva (usuario
     * creado, contraseña cambiada, respuesta o borrado en moderación). Desde
     * ese momento el caso ya no se puede remitir.
     */
    public function up(): void
    {
        Schema::table('chat_reports', function (Blueprint $table) {
            $table->timestamp('managed_at')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_reports', function (Blueprint $table) {
            $table->dropColumn('managed_at');
        });
    }
};
