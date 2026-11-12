<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('created_by')->constrained('events')->nullOnDelete();
            $table->foreignId('assignment_id')->nullable()->after('event_id')->constrained('assignments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assignment_id');
            $table->dropConstrainedForeignId('event_id');
        });
    }
};
