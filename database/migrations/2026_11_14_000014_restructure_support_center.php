<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reestructura el módulo de Moderación como Centro de Ayuda y Soporte.
     *
     * - Cada caso tiene un tipo: moderación y contenido, sugerencia, ayuda o
     *   soporte. Los casos anteriores quedan como moderación.
     * - El tipo soporte puede guardar el módulo o la URL donde ocurrió el
     *   inconveniente, para que la administración vaya a revisarlo.
     * - Los adjuntos se guardan por ticket y por tipo de archivo; los enlaces
     *   van en su propia tabla.
     */
    public function up(): void
    {
        Schema::table('chat_reports', function (Blueprint $table) {
            $table->string('type', 20)->default('moderacion')->after('id');
            $table->string('module_code', 80)->nullable()->after('type');
            $table->string('context_url', 500)->nullable()->after('module_code');
            $table->string('context_label', 160)->nullable()->after('context_url');
            $table->index('type');
        });

        Schema::create('chat_report_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('chat_reports')->cascadeOnDelete();
            $table->string('kind', 20); // image, video, audio, document, evidence
            $table->string('path', 500);
            $table->string('name', 255);
            $table->string('mime', 150)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('is_evidence')->default(false);
            $table->timestamps();

            $table->index(['report_id', 'kind']);
        });

        Schema::create('chat_report_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained('chat_reports')->cascadeOnDelete();
            $table->string('url', 2000);
            $table->timestamps();
        });

        // Nombre visible del módulo. El código interno y la URL no cambian.
        if (Schema::hasTable('modules')) {
            DB::table('modules')
                ->where('code', 'moderacion')
                ->update(['name' => 'Centro de Ayuda y Soporte']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_report_links');
        Schema::dropIfExists('chat_report_attachments');

        Schema::table('chat_reports', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn(['type', 'module_code', 'context_url', 'context_label']);
        });

        if (Schema::hasTable('modules')) {
            DB::table('modules')
                ->where('code', 'moderacion')
                ->update(['name' => 'Moderación']);
        }
    }
};
