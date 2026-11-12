<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_reports', function (Blueprint $table) {
            $table->string('status', 20)->default('pendiente')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('taken_at')->nullable();
            $table->text('attention_observation')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('close_observation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn([
                'status',
                'taken_at',
                'attention_observation',
                'closed_at',
                'close_observation',
            ]);
        });
    }
};
