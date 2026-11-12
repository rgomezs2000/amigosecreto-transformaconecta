<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('type', 20)->default('text')->after('body');
            $table->string('attachment_path')->nullable()->after('type');
            $table->string('attachment_name')->nullable()->after('attachment_path');
            $table->string('attachment_mime')->nullable()->after('attachment_name');
            $table->unsignedBigInteger('attachment_size')->nullable()->after('attachment_mime');
            $table->timestamp('edited_at')->nullable()->after('attachment_size');
            $table->timestamp('deleted_at')->nullable()->after('edited_at');
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete()->after('deleted_at');
            $table->boolean('deleted_by_admin')->default(false)->after('deleted_by');
        });

        Schema::create('chat_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();

            $table->unique(['message_id', 'user_id']);
        });

        Schema::create('chat_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reports');
        Schema::dropIfExists('chat_message_reactions');

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['deleted_by']);
            $table->dropColumn([
                'type',
                'attachment_path',
                'attachment_name',
                'attachment_mime',
                'attachment_size',
                'edited_at',
                'deleted_at',
                'deleted_by',
                'deleted_by_admin',
            ]);
        });
    }
};
