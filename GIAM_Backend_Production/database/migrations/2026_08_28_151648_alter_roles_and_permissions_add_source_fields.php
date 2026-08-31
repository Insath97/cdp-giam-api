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
            $table->enum('source', ['native', 'synced'])->default('native')->after('name');
            $table->timestamp('synced_at')->nullable()->after('source');
        });

        Schema::table($tableNames['permissions'], function (Blueprint $table) {
            $table->enum('source', ['native', 'synced'])->default('native')->after('name');
            $table->timestamp('synced_at')->nullable()->after('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tableNames = config('permission.table_names');

        Schema::table($tableNames['roles'], function (Blueprint $table) {
            $table->dropColumn(['source', 'synced_at']);
        });

        Schema::table($tableNames['permissions'], function (Blueprint $table) {
            $table->dropColumn(['source', 'synced_at']);
        });
    }
};
