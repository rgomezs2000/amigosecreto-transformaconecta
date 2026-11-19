<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $skip = [
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
        $tables = collect(DB::select('SHOW TABLES'))
            ->map(fn ($row) => array_values((array) $row)[0])
            ->filter(fn ($tableName) => ! in_array($tableName, $this->skip, true));

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
        //
    }
};
