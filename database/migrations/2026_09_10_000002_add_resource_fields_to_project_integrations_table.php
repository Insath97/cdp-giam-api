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
        Schema::table('project_integrations', function (Blueprint $table) {
            $table->json('allowed_resources')->nullable()->after('allowed_user_fields')
                  ->comment('Permitted inbound resource scopes e.g. ["employees:read"]');
            $table->json('allowed_resource_fields')->nullable()->after('allowed_resources')
                  ->comment('Per-resource field projection allowlist e.g. {"employees": ["employee_code", "full_name"]}');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_integrations', function (Blueprint $table) {
            $table->dropColumn(['allowed_resources', 'allowed_resource_fields']);
        });
    }
};
