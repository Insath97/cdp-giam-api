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
        Schema::table('project_sync_credentials', function (Blueprint $table) {
            if (Schema::hasColumn('project_sync_credentials', 'pull_roles_endpoint')) {
                $table->dropColumn('pull_roles_endpoint');
            }
            if (Schema::hasColumn('project_sync_credentials', 'pull_permissions_endpoint')) {
                $table->dropColumn('pull_permissions_endpoint');
            }
            if (Schema::hasColumn('project_sync_credentials', 'pull_modules_endpoint')) {
                $table->dropColumn('pull_modules_endpoint');
            }
            if (Schema::hasColumn('project_sync_credentials', 'base_api_url')) {
                $table->dropColumn('base_api_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_sync_credentials', function (Blueprint $table) {
            $table->string('pull_roles_endpoint')->nullable();
            $table->string('pull_permissions_endpoint')->nullable();
            $table->string('pull_modules_endpoint')->nullable();
            $table->string('base_api_url')->nullable();
        });
    }
};
