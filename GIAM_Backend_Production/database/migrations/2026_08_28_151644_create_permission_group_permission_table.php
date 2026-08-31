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
        Schema::create('permission_group_permission', function (Blueprint $table) {
            $table->foreignId('permission_group_id')->constrained('permission_groups')->onDelete('cascade');
            $table->unsignedBigInteger('permission_id'); // Spatie permissions table does not use 'id' conventionally in some older versions, but usually it does. We will constrain it.
            $table->foreign('permission_id')->references('id')->on(config('permission.table_names.permissions', 'permissions'))->onDelete('cascade');
            $table->primary(['permission_group_id', 'permission_id'], 'pgp_primary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permission_group_permission');
    }
};
