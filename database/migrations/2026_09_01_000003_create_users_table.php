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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 50)->unique()->comment('Unique code linking to employees table');
            $table->string('name', 255)->comment('Display name / Full identity name');
            $table->string('username', 100)->unique()->comment('Unique GIAM login handle');
            $table->string('email', 255)->unique()->comment('Primary authentication email');
            $table->string('password', 255)->comment('Bcrypt/Argon2 hashed password');
            $table->enum('user_type', ['staff', 'admin', 'system'])->default('staff')->comment('GIAM user classification');
            $table->boolean('is_active')->default(true)->comment('1 = Active account, 0 = Inactive');
            $table->boolean('can_login')->default(true)->comment('1 = Login permitted, 0 = Login blocked');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('lockout_until')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->rememberToken();
            $table->unsignedInteger('version')->default(1)->comment('Optimistic concurrency version lock');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'can_login', 'user_type'], 'users_status_idx');
            $table->index('created_at', 'users_created_at_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
