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
        Schema::create('project_api_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('name', 100)->comment('Descriptive label for the credential (e.g. Primary Key, Production Sync)');
            $table->string('key_id', 32)->comment('Public key identifier for indexed credential lookup');
            $table->string('key_hash', 64)->comment('HMAC-SHA-256 hash of secret; plaintext is never stored');
            $table->timestamp('last_used_at')->nullable()->comment('Timestamp of last authenticated inbound request');
            $table->timestamp('expires_at')->nullable()->comment('Optional expiration timestamp');
            $table->timestamp('revoked_at')->nullable()->comment('Revocation timestamp; non-null indicates revoked credential');
            $table->timestamps();

            $table->foreign('project_id', 'fk_project_api_keys_project')
                  ->references('id')->on('projects')
                  ->onDelete('cascade');

            $table->unique('key_id', 'uniq_project_api_keys_key_id');
            $table->index(['project_id', 'revoked_at'], 'idx_project_api_keys_project_revoked');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_api_keys');
    }
};
