<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Enforces database-level referential integrity from employees to canonical org_* tables.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('department_code', 'fk_employees_dept')
                  ->references('code')->on('org_departments')
                  ->onUpdate('cascade')
                  ->onDelete('restrict');

            $table->foreign('designation_code', 'fk_employees_desig')
                  ->references('code')->on('org_designations')
                  ->onUpdate('cascade')
                  ->onDelete('restrict');

            $table->foreign('province_code', 'fk_employees_province')
                  ->references('code')->on('org_provinces')
                  ->onUpdate('cascade')
                  ->onDelete('restrict');

            $table->foreign('zonal_code', 'fk_employees_zone')
                  ->references('code')->on('org_zones')
                  ->onUpdate('cascade')
                  ->onDelete('restrict');

            $table->foreign('region_code', 'fk_employees_region')
                  ->references('code')->on('org_regions')
                  ->onUpdate('cascade')
                  ->onDelete('restrict');

            $table->foreign('branch_code', 'fk_employees_branch')
                  ->references('code')->on('org_branches')
                  ->onUpdate('cascade')
                  ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign('fk_employees_dept');
            $table->dropForeign('fk_employees_desig');
            $table->dropForeign('fk_employees_province');
            $table->dropForeign('fk_employees_zone');
            $table->dropForeign('fk_employees_region');
            $table->dropForeign('fk_employees_branch');
        });
    }
};
