<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $softDeleteTables = [
        'users',
        'categories',
        'modules',
        'profiles',
        'permissions',
        'events',
        'help_articles',
        'chat_reports',
        'chat_report_attachments',
        'chat_report_links',
        'chat_report_ratings',
        'wishlists',
        'wish_items',
        'assignments',
        'clues',
        'consumable_gifts',
        'exchange_gifts',
        'event_participants',
        'chat_conversations',
        'chat_message_attachments',
        'chat_blocks',
        'chat_group_bans',
        'chat_group_invitations',
        'chat_group_suspensions',
    ];

    public function up(): void
    {
        foreach ($this->softDeleteTables as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (! Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->softDeletes());
            }

            $after = Schema::hasColumn($tableName, 'deleted_at')
                ? 'deleted_at'
                : (Schema::hasColumn($tableName, 'updated_at') ? 'updated_at' : 'created_at');

            foreach (['created_by', 'updated_by', 'deleted_by'] as $column) {
                if (! Schema::hasColumn($tableName, $column)) {
                    Schema::table($tableName, function (Blueprint $table) use ($column, $after) {
                        $table->unsignedBigInteger($column)->nullable()->after($after);
                    });
                }

                $after = $column;
            }
        }
    }

    public function down(): void
    {
        //
    }
};
