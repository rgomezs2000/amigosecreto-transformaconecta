<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversation_user', function (Blueprint $table) {
            $table->timestamp('muted_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
        });

        Schema::create('chat_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['blocker_id', 'blocked_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_blocks');

        Schema::table('chat_conversation_user', function (Blueprint $table) {
            $table->dropColumn(['muted_at', 'cleared_at']);
        });
    }
};
