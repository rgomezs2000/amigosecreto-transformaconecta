<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('chat_messages')
            ->where('type', 'video')
            ->where('attachment_name', 'audio.webm')
            ->update(['type' => 'audio']);

        DB::table('chat_message_attachments')
            ->where('type', 'video')
            ->where('name', 'audio.webm')
            ->update(['type' => 'audio']);
    }

    public function down(): void
    {
        //
    }
};
