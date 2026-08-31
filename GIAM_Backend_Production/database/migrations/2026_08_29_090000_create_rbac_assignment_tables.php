<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('role_permission_group', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_group_id')->constrained('permission_groups')->cascadeOnDelete();
            $table->primary(['role_id','permission_group_id']);
        });
        Schema::create('model_has_permission_groups', function (Blueprint $table) {
            $table->foreignId('permission_group_id')->constrained('permission_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('model_id');
            $table->string('model_type');
            $table->index(['model_id','model_type']);
            $table->primary(['permission_group_id','model_id','model_type']);
        });
        Schema::table('user_field_access_rules', function (Blueprint $table) {
            $table->unique(['user_id','application_id']);
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->index(['application_id','name']);
        });
        Schema::table('permissions', function (Blueprint $table) {
            $table->index(['application_id','module_id','name']);
        });
        Schema::table('permission_groups', function (Blueprint $table) {
            $table->index(['application_id','name']);
        });
    }
    public function down(): void
    {
        Schema::table('permission_groups', fn(Blueprint $t)=>$t->dropIndex(['application_id','name']));
        Schema::table('permissions', fn(Blueprint $t)=>$t->dropIndex(['application_id','module_id','name']));
        Schema::table('roles', fn(Blueprint $t)=>$t->dropIndex(['application_id','name']));
        Schema::table('user_field_access_rules', fn(Blueprint $t)=>$t->dropUnique(['user_id','application_id']));
        Schema::dropIfExists('model_has_permission_groups');
        Schema::dropIfExists('role_permission_group');
    }
};
