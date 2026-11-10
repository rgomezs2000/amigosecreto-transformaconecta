<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reinicio único de las concesiones por perfil para el nuevo modelo de
     * módulos. A partir de aquí la base de datos es la fuente de verdad y el
     * seeder solo inserta los accesos iniciales si el perfil está vacío.
     */
    public function up(): void
    {
        DB::table('profile_permission_module')->delete();
    }

    public function down(): void
    {
        // No se restauran las concesiones anteriores.
    }
};
