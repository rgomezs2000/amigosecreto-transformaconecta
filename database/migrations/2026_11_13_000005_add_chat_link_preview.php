<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->string('link_url', 2048)->nullable()->after('body');
            $table->string('link_title', 500)->nullable()->after('link_url');
            $table->text('link_description')->nullable()->after('link_title');
            $table->string('link_image', 2048)->nullable()->after('link_description');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn(['link_url', 'link_title', 'link_description', 'link_image']);
        });
    }
};
