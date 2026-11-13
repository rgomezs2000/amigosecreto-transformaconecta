<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestor original y último administrador remitido de un caso.
 *
 * - taken_by:        administrador que tomó y gestionó el caso originalmente.
 * - referred_to_id:  último administrador al que se remitió (el gestor actual
 *                    cuando hubo remisión).
 *
 * Se guardan aparte porque `assigned_to` cambia con cada remisión y se necesita
 * conservar quién lo gestionó primero y a quién se remitió por última vez para
 * mostrarlo y filtrarlo en la lista de reportes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_reports', function (Blueprint $table) {
            $table->foreignId('taken_by')
                ->nullable()
                ->after('assigned_to')
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('referred_to_id')
                ->nullable()
                ->after('taken_by')
                ->constrained('users')
                ->nullOnDelete();
        });

        // Casos ya gestionados: el gestor original es el primero que remitió
        // (si hubo remisión) o el asignado actual.
        foreach (DB::table('chat_reports')->get() as $report) {
            $firstFrom = DB::table('chat_report_referrals')
                ->where('report_id', $report->id)
                ->orderBy('id')
                ->value('from_admin_id');

            $lastTo = DB::table('chat_report_referrals')
                ->where('report_id', $report->id)
                ->orderByDesc('id')
                ->value('to_admin_id');

            DB::table('chat_reports')->where('id', $report->id)->update([
                'taken_by' => $firstFrom ?: $report->assigned_to,
                'referred_to_id' => $lastTo,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('chat_reports', function (Blueprint $table) {
            $table->dropForeign(['referred_to_id']);
            $table->dropForeign(['taken_by']);
        });

        Schema::table('chat_reports', function (Blueprint $table) {
            $table->dropColumn(['referred_to_id', 'taken_by']);
        });
    }
};
