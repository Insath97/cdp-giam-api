<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableNames = config('permission.table_names');

        Schema::table($tableNames['roles'], function (Blueprint $table) {
            $table->dropUnique(['name', 'guard_name']);
            $table->foreignId('application_id')->nullable()->after('id')->constrained('applications')->onDelete('cascade');
            $table->unique(['name', 'guard_name', 'application_id']);
        });

        Schema::table($tableNames['permissions'], function (Blueprint $table) {
            $table->dropUnique(['name', 'guard_name']);
            $table->foreignId('module_id')->nullable()->after('id')->constrained('modules')->onDelete('cascade');
            $table->foreignId('application_id')->nullable()->after('module_id')->constrained('applications')->onDelete('cascade');
            $table->unique(['name', 'guard_name', 'application_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('permission.table_names');

        Schema::table($tableNames['permissions'], function (Blueprint $table) {
            $table->dropUnique(['name', 'guard_name', 'application_id']);
            $table->dropForeign(['application_id']);
            $table->dropForeign(['module_id']);
            $table->dropColumn(['application_id', 'module_id']);
            $table->unique(['name', 'guard_name']);
        });

        Schema::table($tableNames['roles'], function (Blueprint $table) {
            $table->dropUnique(['name', 'guard_name', 'application_id']);
            $table->dropForeign(['application_id']);
            $table->dropColumn(['application_id']);
            $table->unique(['name', 'guard_name']);
        });
    }
};
