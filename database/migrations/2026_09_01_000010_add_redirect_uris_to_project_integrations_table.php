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
            $table->json('redirect_uris')->nullable()->after('encrypted_client_secret')->comment('Exact allowlist of permitted SSO redirect URIs');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_integrations', function (Blueprint $table) {
            $table->dropColumn('redirect_uris');
        });
    }
};
