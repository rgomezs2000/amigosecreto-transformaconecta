<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los obsequios consumibles distinguen el nombre corto del detalle.
 *
 * `description` se conserva para los registros anteriores y para el detalle;
 * `name` guarda el nombre que se muestra en la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consumable_gifts', function (Blueprint $table) {
            $table->string('name', 150)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('consumable_gifts', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
