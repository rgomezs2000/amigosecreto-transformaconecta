<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los emojis deben compararse carácter por carácter. Con la colación
     * `utf8mb4_unicode_ci` todos los emojis pesan lo mismo, así que «👍» y
     * «😀» se consideraban iguales: al reaccionar se borraba otro emoji y se
     * agrupaban reacciones distintas. Con `utf8mb4_bin` cada emoji es único.
     */
    public function up(): void
    {
        Schema::table('chat_message_reactions', function (Blueprint $table) {
            $table->string('emoji', 16)->collation('utf8mb4_bin')->change();
        });
    }

    public function down(): void
    {
        Schema::table('chat_message_reactions', function (Blueprint $table) {
            $table->string('emoji', 16)->collation('utf8mb4_unicode_ci')->change();
        });
    }
};
