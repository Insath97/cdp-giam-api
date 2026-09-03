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
        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->timestamp('requested_at')->useCurrent();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->foreign('user_id', 'fk_pwd_reset_req_user')
                  ->references('id')->on('users')
                  ->onDelete('restrict');

            $table->foreign('reviewed_by_user_id', 'fk_pwd_reset_req_reviewer')
                  ->references('id')->on('users')
                  ->onDelete('restrict');

            // Non-unique index on (user_id, status) allows multiple historical APPROVED/REJECTED rows
            $table->index(['user_id', 'status'], 'idx_pwd_reset_req_user_status');
            $table->index('status', 'idx_pwd_reset_req_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_requests');
    }
};
