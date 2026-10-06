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
        // 1. Main User Project Access Assignment
        Schema::create('user_project_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->enum('status', ['PENDING', 'ACTIVE', 'SUSPENDED', 'REVOKED', 'SYNC_FAILED'])->default('PENDING');
            $table->unsignedBigInteger('assigned_by')->comment('Admin/HR user who performed assignment');
            $table->timestamp('assigned_at')->useCurrent();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 255)->nullable();
            $table->unsignedInteger('version')->default(1)->comment('Optimistic concurrency version lock');
            $table->timestamps();

            $table->unique(['user_id', 'project_id'], 'user_project_unique');
            $table->index(['project_id', 'status'], 'user_project_status_idx');
            $table->index('assigned_by', 'user_project_assigned_by_idx');

            $table->foreign('user_id', 'fk_user_project_access_user')
                  ->references('id')->on('users')
                  ->onDelete('cascade');
            $table->foreign('project_id', 'fk_user_project_access_project')
                  ->references('id')->on('projects')
                  ->onDelete('cascade');
            $table->foreign('assigned_by', 'fk_user_project_access_assigned_by')
                  ->references('id')->on('users');
            $table->foreign('revoked_by', 'fk_user_project_access_revoked_by')
                  ->references('id')->on('users')
                  ->onDelete('set null');
        });

        // 2. Assigned Project Roles
        Schema::create('user_project_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_project_access_id');
            $table->unsignedBigInteger('project_role_id');
            $table->string('external_role_id', 100)->comment('Preserved target project role reference');
            $table->timestamp('assigned_at')->useCurrent();

            $table->unique(['user_project_access_id', 'project_role_id'], 'user_proj_roles_unique');
            $table->index('project_role_id', 'user_proj_roles_role_idx');

            $table->foreign('user_project_access_id', 'fk_upr_access')
                  ->references('id')->on('user_project_access')
                  ->onDelete('cascade');
            $table->foreign('project_role_id', 'fk_upr_role')
                  ->references('id')->on('project_roles')
                  ->onDelete('cascade');
        });

        // 3. Assigned Project Permissions
        Schema::create('user_project_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_project_access_id');
            $table->unsignedBigInteger('project_permission_id');
            $table->string('external_permission_id', 100)->comment('Preserved target permission reference');
            $table->boolean('is_granted')->default(true)->comment('1 = Explicit grant, 0 = Explicit deny');
            $table->timestamp('assigned_at')->useCurrent();

            $table->unique(['user_project_access_id', 'project_permission_id'], 'user_proj_perm_unique');
            $table->index('project_permission_id', 'user_proj_perm_perm_idx');

            $table->foreign('user_project_access_id', 'fk_upp_access')
                  ->references('id')->on('user_project_access')
                  ->onDelete('cascade');
            $table->foreign('project_permission_id', 'fk_upp_permission')
                  ->references('id')->on('project_permissions')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_project_permissions');
        Schema::dropIfExists('user_project_roles');
        Schema::dropIfExists('user_project_access');
    }
};
