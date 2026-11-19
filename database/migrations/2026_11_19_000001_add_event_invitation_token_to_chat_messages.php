<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            // Sin «after('event_id')»: la columna event_id la agrega una
            // migración posterior y en una base nueva todavía no existe.
            $table->string('event_invitation_token', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('event_invitation_token');
        });
    }
};
