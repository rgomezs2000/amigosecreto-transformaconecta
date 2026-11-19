<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('chat_reports')
            ->where('type', 'cuenta')
            ->update(['type' => 'registro']);
    }

    public function down(): void
    {
        DB::table('chat_reports')
            ->where('type', 'registro')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(`payload`, '$.action')) IN ('reset', 'unlock')")
            ->update(['type' => 'cuenta']);
    }
};
