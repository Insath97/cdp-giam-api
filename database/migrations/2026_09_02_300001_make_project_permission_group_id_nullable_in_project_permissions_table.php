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
        Schema::table('project_permissions', function (Blueprint $table) {
            // Drop existing non-nullable foreign key
            $table->dropForeign('fk_project_permissions_group');

            // Change column to nullable
            $table->unsignedBigInteger('project_permission_group_id')->nullable()->change();

            // Re-add foreign key with ON DELETE SET NULL
            $table->foreign('project_permission_group_id', 'fk_project_permissions_group')
                  ->references('id')->on('project_permission_groups')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_permissions', function (Blueprint $table) {
            $table->dropForeign('fk_project_permissions_group');

            $table->unsignedBigInteger('project_permission_group_id')->nullable(false)->change();

            $table->foreign('project_permission_group_id', 'fk_project_permissions_group')
                  ->references('id')->on('project_permission_groups')
                  ->onDelete('cascade');
        });
    }
};
