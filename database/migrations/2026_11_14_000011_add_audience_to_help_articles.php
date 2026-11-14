<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audiencia de cada artículo de ayuda.
     *
     * «all»   → visible para cualquier persona con acceso al módulo.
     * «admin» → visible solo para cuentas con rol Administrador.
     *
     * El contenido de administración no se oculta con estilos: la consulta lo
     * excluye para los participantes, así que no llega a su HTML.
     */
    public function up(): void
    {
        if (! Schema::hasTable('help_articles') || Schema::hasColumn('help_articles', 'audience')) {
            return;
        }

        Schema::table('help_articles', function (Blueprint $table) {
            $table->string('audience', 20)->default('all')->after('kind');
            $table->index(['module_id', 'audience', 'sort_order']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('help_articles') || ! Schema::hasColumn('help_articles', 'audience')) {
            return;
        }

        Schema::table('help_articles', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
    }
};
