<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('previous_status', 20)->nullable()->after('status');
            $table->text('status_reason')->nullable()->after('previous_status');
            $table->dateTime('started_at')->nullable()->after('exchange_at');
            $table->dateTime('suspended_at')->nullable()->after('started_at');
            $table->dateTime('postponed_at')->nullable()->after('suspended_at');
        });

        Schema::table('event_participants', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['previous_status', 'status_reason', 'started_at', 'suspended_at', 'postponed_at']);
        });

        Schema::table('event_participants', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
