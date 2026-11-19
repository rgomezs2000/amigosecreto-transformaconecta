<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('modules')
            ->where('code', 'moderacion')
            ->update(['icon' => 'fa-headset']);
    }

    public function down(): void
    {
        DB::table('modules')
            ->where('code', 'moderacion')
            ->update(['icon' => 'fa-shield-halved']);
    }
};
