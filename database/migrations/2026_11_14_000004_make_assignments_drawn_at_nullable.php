<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `drawn_at` marca cuándo sacó su amigo cada participante.
     *
     * El reparto se calcula completo en el primer ingreso al módulo, así que
     * las parejas existen antes de que cada quien entre: la columna tiene que
     * admitir nulos hasta que el participante descubra a su amigo.
     */
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dateTime('drawn_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dateTime('drawn_at')->nullable(false)->change();
        });
    }
};
