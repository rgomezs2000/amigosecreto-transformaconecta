<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasa de cambio del día (USD -> VES).
 *
 * Se guarda el histórico porque los valores de los obsequios se registran en
 * USD y el administrador necesita ver el equivalente en bolívares a la fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('rate_date');
            $table->char('base', 3)->default('USD');
            $table->char('quote', 3)->default('VES');
            $table->decimal('rate', 18, 6);

            // 'oficial' (BCV) o 'paralelo', según la fuente.
            $table->string('source', 50);

            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['rate_date', 'quote', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
