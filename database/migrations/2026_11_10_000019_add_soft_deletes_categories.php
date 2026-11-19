<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'categories';

        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (! Schema::hasColumn($tableName, 'deleted_at')) {
            Schema::table($tableName, fn (Blueprint $table) => $table->softDeletes());
        }

        $after = Schema::hasColumn($tableName, 'updated_at') ? 'updated_at' : 'created_at';

        foreach (['created_by', 'updated_by', 'deleted_by'] as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                Schema::table($tableName, function (Blueprint $table) use ($column, $after) {
                    $table->unsignedBigInteger($column)->nullable()->after($after);
                });
            }

            $after = $column;
        }
    }

    public function down(): void
    {
        //
    }
};
