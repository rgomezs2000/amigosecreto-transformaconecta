<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mensaje detallado del caso y encuesta de la sugerencia.
     *
     * - case_message: el texto que escribe el usuario (o el administrador) y
     *   que se copia al chat del caso para que el gestor lo lea.
     * - suggestion: el texto propio de una sugerencia.
     * - chat_report_ratings: las seis valoraciones de una sugerencia.
     */
    public function up(): void
    {
        Schema::table('chat_reports', function (Blueprint $table) {
            $table->text('case_message')->nullable()->after('reason');
            $table->text('suggestion')->nullable()->after('case_message');
        });

        Schema::create('chat_report_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('chat_reports')->cascadeOnDelete();
            $table->string('aspect', 30);
            $table->unsignedTinyInteger('score');
            $table->timestamps();

            $table->unique(['report_id', 'aspect']);
        });

        // Los casos anteriores ya tienen el mensaje del chat reportado; se
        // copia para que la ficha de administración muestre el mismo texto.
        foreach (DB::table('chat_reports')->whereNotNull('message_id')->get() as $report) {
            $body = DB::table('chat_messages')->where('id', $report->message_id)->value('body');

            if ($body !== null && trim(strip_tags((string) $body)) !== '') {
                DB::table('chat_reports')->where('id', $report->id)->update([
                    'case_message' => $body,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_report_ratings');

        Schema::table('chat_reports', function (Blueprint $table) {
            $table->dropColumn(['case_message', 'suggestion']);
        });
    }
};
