<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Replaces cascade delete on employee_id with restrict to preserve compliance and audit history.
     */
    public function up(): void
    {
        Schema::table('project_access_requests', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);

            $table->foreign('employee_id', 'fk_par_employee')
                  ->references('id')->on('employees')
                  ->onUpdate('cascade')
                  ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_access_requests', function (Blueprint $table) {
            $table->dropForeign('fk_par_employee');

            $table->foreign('employee_id')
                  ->references('id')->on('employees')
                  ->onDelete('cascade');
        });
    }
};
