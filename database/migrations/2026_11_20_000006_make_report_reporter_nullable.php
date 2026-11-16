<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las solicitudes públicas (prerregistro) no tienen un usuario que las
     * reporte todavía.
     */
    public function up(): void
    {
        Schema::table('chat_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('reporter_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('chat_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('reporter_id')->nullable(false)->change();
        });
    }
};
