<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El módulo de perfil se llama «Perfil» y su ruta canónica es /perfil.
     *
     * Instalaciones anteriores lo sembraban como «perfiles»; aquí se deja el
     * slug en su forma actual y la ruta antigua pasa a responder con un 301
     * (la redirección la registra la migración que creó module_redirects).
     */
    public function up(): void
    {
        $module = DB::table('modules')->where('code', 'perfil')->first();

        if ($module === null || $module->slug !== 'perfiles') {
            return;
        }

        $taken = DB::table('modules')
            ->where('slug', 'perfil')
            ->where('id', '!=', $module->id)
            ->exists();

        if ($taken) {
            return;
        }

        DB::table('modules')->where('id', $module->id)->update([
            'slug' => 'perfil',
            'updated_at' => now(),
        ]);

        if (DB::getSchemaBuilder()->hasTable('module_redirects')) {
            DB::table('module_redirects')->insertOrIgnore([
                'path' => 'perfiles',
                'module_id' => $module->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // El slug es un dato editable: no se revierte automáticamente.
    }
};
