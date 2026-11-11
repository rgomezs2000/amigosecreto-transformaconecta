<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Crea las categorías de módulos y asocia cada módulo existente a una.
     * En una instalación nueva las crea el CategorySeeder.
     */
    public function up(): void
    {
        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 60)->unique();
                $table->string('slug', 80)->unique();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('modules', 'category_id')) {
            Schema::table('modules', function (Blueprint $table) {
                $table->unsignedBigInteger('category_id')->nullable()->after('group')->index();
            });
        }

        $groups = DB::table('modules')->select('group')->distinct()->pluck('group');

        $defaultOrder = [
            'General' => 1,
            'Maestros' => 2,
            'Usuarios' => 3,
            'Eventos' => 4,
            'Amigo secreto' => 5,
            'Notificaciones' => 6,
            'Sesión' => 7,
        ];

        $next = 1;
        $now = now();

        foreach ($groups as $group) {
            if ($group === null || trim((string) $group) === '') {
                continue;
            }

            $sort = $defaultOrder[$group] ?? $next;
            $next = max($next, $sort) + 1;

            $categoryId = DB::table('categories')->where('name', $group)->value('id');

            if ($categoryId === null) {
                $categoryId = DB::table('categories')->insertGetId([
                    'name' => $group,
                    'slug' => Str::slug($group) ?: 'categoria',
                    'sort_order' => $sort,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('modules')
                ->where('group', $group)
                ->update(['category_id' => $categoryId, 'updated_at' => $now]);
        }

        if (! Schema::hasTable('categories')) {
            return;
        }

        Schema::table('modules', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE modules MODIFY category_id BIGINT UNSIGNED NOT NULL');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('modules') && Schema::hasColumn('modules', 'category_id')) {
            Schema::table('modules', function (Blueprint $table) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            });
        }

        Schema::dropIfExists('categories');
    }
};
