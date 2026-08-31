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
        Schema::table('roles', function (Blueprint $table) {
            $table->index('application_id');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->index('application_id');
            $table->index('module_id');
        });

        Schema::table('permission_groups', function (Blueprint $table) {
            $table->index('application_id');
        });

        Schema::table('user_project_sync_logs', function (Blueprint $table) {
            $table->index('application_id');
            $table->index('user_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex(['application_id']);
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropIndex(['application_id']);
            $table->dropIndex(['module_id']);
        });

        Schema::table('permission_groups', function (Blueprint $table) {
            $table->dropIndex(['application_id']);
        });

        Schema::table('user_project_sync_logs', function (Blueprint $table) {
            $table->dropIndex(['application_id']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['status']);
        });
    }
};
