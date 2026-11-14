<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encuestas del chat: viven dentro de un mensaje y agrupan sus opciones y
     * los votos de los participantes (uno por opción y usuario).
     */
    public function up(): void
    {
        Schema::create('chat_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('question', 300);
            $table->boolean('multiple')->default(false);
            $table->boolean('hide_voters')->default(false);
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('chat_polls')->cascadeOnDelete();
            $table->string('label', 200);
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('chat_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('chat_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('chat_poll_options')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['option_id', 'user_id']);
            $table->index(['poll_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_poll_votes');
        Schema::dropIfExists('chat_poll_options');
        Schema::dropIfExists('chat_polls');
    }
};
