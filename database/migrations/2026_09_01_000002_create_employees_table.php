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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 50)->unique()->comment('Unique Employee Code (e.g. EMP1001)');
            $table->string('f_name', 100)->comment('First Name (e.g. John)');
            $table->string('l_name', 100)->comment('Last Name (e.g. Doe)');
            $table->string('full_name', 255)->comment('Full Name (e.g. John Doe)');
            $table->string('name_with_initials', 150)->comment('Name with Initials (e.g. J. Doe)');
            $table->enum('employee_type', ['permanent', 'contract', 'probation', 'intern', 'part_time'])->default('permanent');
            $table->enum('id_type', ['nic', 'passport', 'driving_license'])->default('nic');
            $table->string('id_number', 50)->comment('Identity Document Number (e.g. 951234567V)');
            $table->date('date_of_birth')->comment('Date of Birth (e.g. 1995-05-15)');
            $table->string('email', 255)->comment('Corporate/Official email address');
            $table->string('phone', 30)->comment('General phone contact (e.g. +94112345678)');
            $table->string('address_line_1', 255)->comment('Primary street address line');
            $table->string('city', 100)->comment('City (e.g. Colombo)');
            $table->string('state', 100)->nullable()->comment('State / District / Region');
            $table->string('country', 100)->default('Sri Lanka')->comment('Country of residence');
            $table->string('postal_code', 20)->nullable()->comment('Postal / Zip code');
            $table->string('phone_primary', 30)->comment('Primary mobile contact number');
            $table->string('phone_secondary', 30)->nullable()->comment('Secondary mobile contact number');
            $table->boolean('have_whatsapp')->default(false)->comment('1 = Yes, 0 = No');
            $table->string('whatsapp_number', 30)->nullable()->comment('WhatsApp contact number');
            $table->date('start_date')->comment('Employment commencement date');
            $table->date('end_date')->nullable()->comment('Employment end / termination date');
            $table->boolean('is_active')->default(true)->comment('1 = Active employee, 0 = Inactive/Left');

            // Organizational References
            $table->string('province_code', 50)->comment('FK to org_provinces (e.g. WP)');
            $table->string('zonal_code', 50)->comment('FK to org_zones (e.g. Z01)');
            $table->string('region_code', 50)->comment('FK to org_regions (e.g. R01)');
            $table->string('branch_code', 50)->nullable()->comment('FK to org_branches (e.g. BR01)');
            $table->string('department_code', 50)->comment('FK to org_departments (e.g. DEP01)');
            $table->string('designation_code', 50)->comment('FK to org_designations (e.g. DES01)');
            $table->string('reporting_manager_code', 50)->nullable()->comment('Self-reference to employees.employee_code');

            $table->unsignedInteger('version')->default(1)->comment('Optimistic concurrency version lock');
            $table->timestamps();
            $table->softDeletes();

            // Indexes & Constraints
            $table->unique(['id_type', 'id_number'], 'employees_id_doc_unique');
            $table->index(['l_name', 'f_name'], 'employees_names_idx');
            $table->index('email', 'employees_email_idx');
            $table->index(['province_code', 'zonal_code', 'region_code', 'branch_code'], 'employees_org_hierarchy_idx');
            $table->index(['department_code', 'designation_code'], 'employees_dept_desig_idx');
            $table->index('reporting_manager_code', 'employees_reporting_manager_idx');
            $table->index(['is_active', 'employee_type'], 'employees_status_idx');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreign('reporting_manager_code', 'fk_employees_reporting_manager')
                  ->references('employee_code')->on('employees')
                  ->onUpdate('cascade')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
