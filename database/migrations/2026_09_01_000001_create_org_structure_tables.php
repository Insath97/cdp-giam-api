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
        // 1. Provinces
        Schema::create('org_provinces', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code', 50)->unique()->comment('Province code (e.g. WP, CP, SP)');
            $table->string('name', 100)->comment('Province Name (e.g. Western Province)');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Zones
        Schema::create('org_zones', function (Blueprint $table) {
            $table->increments('id');
            $table->string('province_code', 50);
            $table->string('code', 50)->unique()->comment('Zone code (e.g. Z01)');
            $table->string('name', 100)->comment('Zone Name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('province_code', 'org_zones_province_idx');
            $table->foreign('province_code', 'fk_zones_province')
                  ->references('code')->on('org_provinces')
                  ->onUpdate('cascade');
        });

        // 3. Regions
        Schema::create('org_regions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('zonal_code', 50);
            $table->string('code', 50)->unique()->comment('Region code (e.g. R01)');
            $table->string('name', 100)->comment('Region Name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('zonal_code', 'org_regions_zone_idx');
            $table->foreign('zonal_code', 'fk_regions_zone')
                  ->references('code')->on('org_zones')
                  ->onUpdate('cascade');
        });

        // 4. Branches
        Schema::create('org_branches', function (Blueprint $table) {
            $table->increments('id');
            $table->string('region_code', 50);
            $table->string('code', 50)->unique()->comment('Branch code (e.g. BR01)');
            $table->string('name', 100)->comment('Branch Name');
            $table->string('city', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('region_code', 'org_branches_region_idx');
            $table->foreign('region_code', 'fk_branches_region')
                  ->references('code')->on('org_regions')
                  ->onUpdate('cascade');
        });

        // 5. Departments
        Schema::create('org_departments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code', 50)->unique()->comment('Department code (e.g. DEP01)');
            $table->string('name', 100)->comment('Department Name (e.g. Human Resources)');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 6. Designations
        Schema::create('org_designations', function (Blueprint $table) {
            $table->increments('id');
            $table->string('department_code', 50);
            $table->string('code', 50)->unique()->comment('Designation code (e.g. DES01)');
            $table->string('name', 100)->comment('Designation Title (e.g. Senior Software Engineer)');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('department_code', 'org_designations_dept_idx');
            $table->foreign('department_code', 'fk_designations_dept')
                  ->references('code')->on('org_departments')
                  ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('org_designations');
        Schema::dropIfExists('org_departments');
        Schema::dropIfExists('org_branches');
        Schema::dropIfExists('org_regions');
        Schema::dropIfExists('org_zones');
        Schema::dropIfExists('org_provinces');
    }
};
