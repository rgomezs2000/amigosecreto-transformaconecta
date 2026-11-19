<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Borrado lógico y trazabilidad de quién creó, actualizó o eliminó.
     *
     * deleted_at se agrega a las entidades de negocio que se eliminan desde la
     * interfaz. created_by, updated_by y deleted_by se agregan a las tablas de
     * negocio que tienen id y created_at.
     */
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

    private array $skipAuditTables = [
        'migrations',
        'cache',
        'cache_locks',
        'sessions',
        'password_reset_tokens',
        'jobs',
        'job_batches',
        'failed_jobs',
    ];

    public function up(): void
    {
        foreach ($this->softDeleteTables as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->softDeletes();
                });
            }
        }

        $tables = collect(DB::select('SHOW TABLES'))
            ->map(fn ($row) => array_values((array) $row)[0])
            ->filter(fn ($tableName) => ! in_array($tableName, $this->skipAuditTables, true));

        foreach ($tables as $tableName) {
            if (! Schema::hasColumn($tableName, 'id') || ! Schema::hasColumn($tableName, 'created_at')) {
                continue;
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
        foreach ($this->softDeleteTables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropSoftDeletes();
                });
            }
        }

        // Las columnas de auditoría se conservan: borrarlas perdería la
        // trazabilidad ya registrada.
    }
};
