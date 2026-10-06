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
        // 1. Project Modules (Optional)
        Schema::create('project_modules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('external_module_id', 100)->comment('ID owned by target project');
            $table->string('code', 50)->comment('Module identifier within project');
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();

            $table->unique(['project_id', 'external_module_id'], 'project_modules_external_unique');
            $table->index(['project_id', 'code'], 'project_modules_lookup_idx');
            $table->foreign('project_id', 'fk_project_modules_project')
                  ->references('id')->on('projects')
                  ->onDelete('cascade');
        });

        // 2. Project Roles
        Schema::create('project_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('external_role_id', 100)->comment('Role ID owned by target project');
            $table->string('code', 50);
            $table->string('name', 100)->comment('e.g. HRMS Manager, Centrix Operator');
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();

            $table->unique(['project_id', 'external_role_id'], 'project_roles_external_unique');
            $table->index(['project_id', 'is_active'], 'project_roles_lookup_idx');
            $table->foreign('project_id', 'fk_project_roles_project')
                  ->references('id')->on('projects')
                  ->onDelete('cascade');
        });

        // 3. Project Permission Groups
        Schema::create('project_permission_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('project_module_id')->nullable()->comment('Optional linkage to module');
            $table->string('external_group_id', 100)->comment('Group ID owned by target project');
            $table->string('code', 50);
            $table->string('name', 100)->comment('e.g. Employee Records, Order Management');
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();

            $table->unique(['project_id', 'external_group_id'], 'project_perm_groups_external_unique');
            $table->index('project_module_id', 'project_perm_groups_module_idx');
            $table->foreign('project_id', 'fk_project_perm_groups_project')
                  ->references('id')->on('projects')
                  ->onDelete('cascade');
            $table->foreign('project_module_id', 'fk_project_perm_groups_module')
                  ->references('id')->on('project_modules')
                  ->onDelete('set null');
        });

        // 4. Project Permissions
        Schema::create('project_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('project_permission_group_id');
            $table->string('external_permission_id', 100)->comment('Permission ID owned by project');
            $table->string('code', 50);
            $table->string('name', 100)->comment('e.g. Employee View, Order Update');
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();

            $table->unique(['project_id', 'external_permission_id'], 'project_permissions_external_unique');
            $table->index('project_permission_group_id', 'project_permissions_group_idx');
            $table->foreign('project_id', 'fk_project_permissions_project')
                  ->references('id')->on('projects')
                  ->onDelete('cascade');
            $table->foreign('project_permission_group_id', 'fk_project_permissions_group')
                  ->references('id')->on('project_permission_groups')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_permissions');
        Schema::dropIfExists('project_permission_groups');
        Schema::dropIfExists('project_roles');
        Schema::dropIfExists('project_modules');
    }
};
