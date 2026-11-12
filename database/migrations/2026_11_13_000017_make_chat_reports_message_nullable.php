<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reportes de usuario.
 *
 * Desde la pestaña Usuarios se puede reportar a una persona aunque todavía no
 * haya mensajes con ella, así que el reporte no siempre apunta a un mensaje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_reports', function (Blueprint $table) {
            $table->foreignId('message_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('chat_reports', function (Blueprint $table) {
            $table->foreignId('message_id')->nullable(false)->change();
        });
    }
};
