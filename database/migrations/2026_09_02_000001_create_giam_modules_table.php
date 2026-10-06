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
        // 1. Create GIAM internal modules table
        Schema::create('giam_modules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->comment('Module code: USER_EMPLOYEE_MGMT, PROJECT_REGISTRY, etc.');
            $table->string('name', 100);
            $table->string('description', 255)->nullable();
            $table->string('icon', 50)->nullable();
            $table->unsignedInteger('order_index')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Link giam_permission_groups to giam_modules
        Schema::table('giam_permission_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('module_id')->nullable()->after('id');
            $table->foreign('module_id', 'fk_permission_groups_module')
                  ->references('id')->on('giam_modules')
                  ->onDelete('set null');
            $table->index('module_id', 'giam_permission_groups_module_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('giam_permission_groups', function (Blueprint $table) {
            $table->dropForeign('fk_permission_groups_module');
            $table->dropIndex('giam_permission_groups_module_idx');
            $table->dropColumn('module_id');
        });

        Schema::dropIfExists('giam_modules');
    }
};
