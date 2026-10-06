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
        // 1. Audit Logs
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable()->comment('User who performed action (NULL for system/unauthenticated)');
            $table->string('action', 100)->comment('e.g. LOGIN_SUCCESS, USER_CREATED, PROJECT_ACCESS_GRANTED');
            $table->string('entity_type', 100)->comment('e.g. User, Employee, UserProjectAccess, Role');
            $table->string('entity_id', 100)->comment('Surrogate ID or Code of affected entity');
            $table->unsignedBigInteger('project_id')->nullable()->comment('Associated project if applicable');
            $table->string('ip_address', 45);
            $table->string('user_agent', 255)->nullable();
            $table->string('request_id', 64)->nullable()->comment('Correlation ID for distributed tracing');
            $table->json('before_data')->nullable()->comment('Snapshot before mutation (sensitive fields masked)');
            $table->json('after_data')->nullable()->comment('Snapshot after mutation (sensitive fields masked)');
            $table->enum('status', ['SUCCESS', 'FAILED', 'WARNING'])->default('SUCCESS');
            $table->json('metadata')->nullable()->comment('Additional context or parameters');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['actor_user_id', 'created_at'], 'audit_logs_actor_idx');
            $table->index('action', 'audit_logs_action_idx');
            $table->index(['entity_type', 'entity_id'], 'audit_logs_entity_idx');
            $table->index('project_id', 'audit_logs_project_idx');
            $table->index('request_id', 'audit_logs_request_id_idx');
            $table->index('created_at', 'audit_logs_timeline_idx');

            $table->foreign('actor_user_id', 'fk_audit_logs_actor')
                  ->references('id')->on('users')
                  ->onDelete('set null');
            $table->foreign('project_id', 'fk_audit_logs_project')
                  ->references('id')->on('projects')
                  ->onDelete('set null');
        });

        // 2. User Creation Drafts
        Schema::create('user_creation_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('creator_user_id');
            $table->string('draft_token', 64)->unique()->comment('UUID identifier for draft session');
            $table->unsignedInteger('current_step')->default(1)->comment('1 = Basic Info, 2 = Project Access, 3 = Review');
            $table->json('form_data')->comment('Sanitized form payload saved so far');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['creator_user_id', 'expires_at'], 'user_creation_drafts_creator_idx');

            $table->foreign('creator_user_id', 'fk_user_drafts_creator')
                  ->references('id')->on('users')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_creation_drafts');
        Schema::dropIfExists('audit_logs');
    }
};
