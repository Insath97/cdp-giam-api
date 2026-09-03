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
        // 1. Projects Registry
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->comment('Canonical unique project code (e.g. hrms, centrix)');
            $table->string('name', 100)->comment('Display name (e.g. HRMS Portal, Centrix Logistics)');
            $table->text('description')->nullable();
            $table->string('base_url', 255)->comment('Project Web base URL');
            $table->string('icon_url', 255)->nullable();
            $table->enum('status', ['active', 'maintenance', 'disabled'])->default('active');
            $table->timestamps();
        });

        // 2. Project Integrations & Data Projection Filtering
        Schema::create('project_integrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->unique();
            $table->string('api_base_url', 255)->comment('Direct backend-to-backend API endpoint');
            $table->enum('auth_method', ['bearer_token', 'oauth2', 'hmac', 'api_key'])->default('bearer_token');
            $table->string('client_id', 100)->nullable();
            $table->text('encrypted_client_secret')->nullable()->comment('Encrypted credentials using APP_KEY');
            $table->json('allowed_user_fields')->comment('Whitelist JSON array of permitted fields for data projection');
            $table->boolean('sync_enabled')->default(true);
            $table->boolean('sso_enabled')->default(true);
            $table->enum('status', ['healthy', 'degraded', 'offline'])->default('healthy');
            $table->timestamp('last_health_check_at')->nullable();
            $table->timestamp('last_sync_catalog_at')->nullable();
            $table->timestamps();

            $table->foreign('project_id', 'fk_project_integrations_project')
                  ->references('id')->on('projects')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_integrations');
        Schema::dropIfExists('projects');
    }
};
