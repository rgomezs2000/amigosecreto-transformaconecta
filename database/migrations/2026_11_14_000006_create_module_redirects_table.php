<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rutas antiguas de los módulos.
     *
     * Cuando el slug de un módulo (o el de su categoría) cambia, su URL
     * pública cambia con él. Aquí se guarda la ruta anterior para responder
     * con un 301 hacia la nueva en lugar de un 404, de modo que los enlaces
     * ya compartidos sigan funcionando.
     */
    public function up(): void
    {
        if (! Schema::hasTable('modules') || Schema::hasTable('module_redirects')) {
            return;
        }

        Schema::create('module_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('path', 160)->unique();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->timestamps();
        });

        // La página de perfil se llamó /perfiles antes de que su slug pasara a
        // ser «perfil»; la configuración de notificaciones vivía en
        // /administracion/notificaciones antes de seguir la ruta del módulo.
        $this->remember('perfiles', 'perfil');
        $this->remember('administracion/notificaciones', 'control-notificaciones');
    }

    public function down(): void
    {
        Schema::dropIfExists('module_redirects');
    }

    /**
     * Registra una ruta anterior si el módulo existe en esta instalación.
     */
    private function remember(string $path, string $code): void
    {
        $moduleId = DB::table('modules')->where('code', $code)->value('id');

        if ($moduleId === null) {
            return;
        }

        DB::table('module_redirects')->insertOrIgnore([
            'path' => $path,
            'module_id' => $moduleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
