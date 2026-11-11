<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Ubica "Categorías" antes de "Módulos" dentro de Maestros.
     */
    public function up(): void
    {
        if (DB::table('modules')->doesntExist()) {
            return;
        }

        DB::table('modules')
            ->where('slug', 'maestros.categorias')
            ->update(['order' => 25, 'updated_at' => now()]);
    }

    public function down(): void
    {
        //
    }
};
