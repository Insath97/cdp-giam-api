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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password_changed_at')
                  ->comment('1 = Must change password upon next login, 0 = Normal');
            $table->unsignedSmallInteger('self_service_reset_count')->default(0)->after('must_change_password')
                  ->comment('Lifetime count of completed self-service password resets (capped at 3)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'self_service_reset_count']);
        });
    }
};
