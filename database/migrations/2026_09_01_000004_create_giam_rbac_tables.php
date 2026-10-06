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
        // 1. GIAM Permission Groups
        Schema::create('giam_permission_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->comment('Group code (e.g. USER_MGMT, PROJECT_ACCESS)');
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        // 2. Permissions (Spatie Compatible with group link)
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('permission_group_id')->nullable();
            $table->string('name', 125)->comment('Permission Name (e.g. USER_CREATE, ACCESS_ASSIGN)');
            $table->string('guard_name', 125)->default('web');
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['name', 'guard_name'], 'permissions_name_guard_name_unique');
            $table->index('permission_group_id', 'permissions_group_idx');
            $table->foreign('permission_group_id', 'fk_permissions_group')
                  ->references('id')->on('giam_permission_groups')
                  ->onDelete('set null');
        });

        // 3. Roles (Spatie Compatible)
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 125)->comment('Role Name: Super Admin, Admin, HR, Staff');
            $table->string('guard_name', 125)->default('web');
            $table->string('description', 255)->nullable();
            $table->boolean('is_system_reserved')->default(false);
            $table->timestamps();

            $table->unique(['name', 'guard_name'], 'roles_name_guard_name_unique');
        });

        // 4. Model Has Permissions
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type', 191);
            $table->unsignedBigInteger('model_id');

            $table->primary(['permission_id', 'model_id', 'model_type']);
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->foreign('permission_id', 'fk_model_has_permissions_permission')
                  ->references('id')->on('permissions')
                  ->onDelete('cascade');
        });

        // 5. Model Has Roles
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type', 191);
            $table->unsignedBigInteger('model_id');

            $table->primary(['role_id', 'model_id', 'model_type']);
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->foreign('role_id', 'fk_model_has_roles_role')
                  ->references('id')->on('roles')
                  ->onDelete('cascade');
        });

        // 6. Role Has Permissions
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');

            $table->primary(['permission_id', 'role_id']);
            $table->index('role_id', 'role_has_permissions_role_id_foreign');
            $table->foreign('permission_id', 'fk_role_has_permissions_permission')
                  ->references('id')->on('permissions')
                  ->onDelete('cascade');
            $table->foreign('role_id', 'fk_role_has_permissions_role')
                  ->references('id')->on('roles')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('giam_permission_groups');
    }
};
