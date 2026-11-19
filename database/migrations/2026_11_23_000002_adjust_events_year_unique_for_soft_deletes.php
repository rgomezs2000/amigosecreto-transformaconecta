<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique('events_year_unique');
        });

        // Permite conservar el evento anterior con deleted_at y crear uno
        // nuevo para el mismo año sin chocar con la clave única.
        Schema::table('events', function (Blueprint $table) {
            $table->unique(['year', 'deleted_at'], 'events_year_deleted_unique');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique('events_year_deleted_unique');
            $table->unique('year', 'events_year_unique');
        });
    }
};
