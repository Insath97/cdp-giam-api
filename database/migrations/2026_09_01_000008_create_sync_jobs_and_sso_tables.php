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
        // 1. Asynchronous Sync Jobs
        Schema::create('sync_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 64)->unique()->comment('Unique UUID/hash preventing duplicate executions');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->enum('operation', ['CREATE_USER', 'UPDATE_USER', 'ASSIGN_ACCESS', 'REVOKE_ACCESS', 'DEACTIVATE_USER']);
            $table->json('payload')->comment('Pre-filtered, validated payload sent to downstream project API');
            $table->enum('status', ['PENDING', 'PROCESSING', 'SUCCESS', 'FAILED', 'RETRYING'])->default('PENDING');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->text('last_error')->nullable();
            $table->text('response_body')->nullable();
            $table->integer('http_status_code')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_retry_at'], 'sync_jobs_queue_idx');
            $table->index(['user_id', 'project_id'], 'sync_jobs_user_project_idx');

            $table->foreign('user_id', 'fk_sync_jobs_user')
                  ->references('id')->on('users')
                  ->onDelete('cascade');
            $table->foreign('project_id', 'fk_sync_jobs_project')
                  ->references('id')->on('projects')
                  ->onDelete('cascade');
        });

        // 2. SSO Authorization Codes
        Schema::create('sso_auth_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code_hash', 64)->unique()->comment('SHA-256 hash of single-use authorization code');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->string('redirect_uri', 255);
            $table->string('code_challenge', 128)->nullable()->comment('PKCE code challenge');
            $table->string('code_challenge_method', 10)->default('S256');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'project_id', 'expires_at', 'used_at'], 'sso_auth_codes_verification_idx');

            $table->foreign('user_id', 'fk_sso_codes_user')
                  ->references('id')->on('users')
                  ->onDelete('cascade');
            $table->foreign('project_id', 'fk_sso_codes_project')
                  ->references('id')->on('projects')
                  ->onDelete('cascade');
        });

        // 3. User Sessions
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->string('id', 128)->primary()->comment('Laravel session ID');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity');

            $table->index('user_id', 'user_sessions_user_id_index');
            $table->index('last_activity', 'user_sessions_last_activity_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('sso_auth_codes');
        Schema::dropIfExists('sync_jobs');
    }
};
