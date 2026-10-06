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
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 64)->comment('SHA-256 hash of random reset token');
            $table->timestamp('expires_at')->comment('Timestamp after which token is expired');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id', 'fk_password_reset_tokens_user')
                  ->references('id')->on('users')
                  ->onDelete('cascade');

            $table->index(['user_id', 'token_hash'], 'idx_pwd_reset_user_token');
            $table->index('expires_at', 'idx_pwd_reset_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
