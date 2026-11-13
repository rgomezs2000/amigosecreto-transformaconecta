<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remisiones de un caso de reporte entre administradores.
 *
 * Cada remisión guarda quién lo remitió, a quién y la observación con el
 * motivo. El caso cambia de administrador asignado y el chat del ticket pasa
 * al nuevo gestor; el anterior deja de tener acceso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_report_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('chat_reports')->cascadeOnDelete();
            $table->foreignId('from_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observation');
            $table->timestamps();

            $table->index(['report_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_report_referrals');
    }
};
