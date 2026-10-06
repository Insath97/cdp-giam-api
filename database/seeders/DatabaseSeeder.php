<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Master Seeders
        $this->call([
            OrgStructureSeeder::class,
            GiamRbacSeeder::class,
            ProjectRegistrySeeder::class,
        ]);

        // 2. Canonical Development Sample Employee (db-schema.md Section 5.1)
        $employee = Employee::updateOrCreate(
            ['employee_code' => 'EMP1001'],
            [
                'f_name' => 'John',
                'l_name' => 'Doe',
                'full_name' => 'John Doe',
                'name_with_initials' => 'J. Doe',
                'employee_type' => 'permanent',
                'id_type' => 'nic',
                'id_number' => '951234567V',
                'date_of_birth' => '1995-05-15',
                'email' => 'sample@example.com',
                'phone' => '+94112345678',
                'address_line_1' => '123 Main St',
                'city' => 'Colombo',
                'state' => 'Western',
                'country' => 'Sri Lanka',
                'postal_code' => '00100',
                'phone_primary' => '+94812345678',
                'phone_secondary' => '+94812345679',
                'have_whatsapp' => true,
                'whatsapp_number' => '+94812345678',
                'start_date' => '2026-06-01',
                'end_date' => null,
                'is_active' => true,
                'province_code' => 'WP',
                'zonal_code' => 'Z01',
                'region_code' => 'R01',
                'branch_code' => 'BR01',
                'department_code' => 'DEP01',
                'designation_code' => 'DES01',
                'reporting_manager_code' => null,
            ]
        );

        // 3. Local Development / Test Super Admin User (db-schema.md Section 5.2 - Dev/Test only)
        $user = User::updateOrCreate(
            ['username' => 'user01'],
            [
                'employee_code' => 'EMP1001',
                'name' => 'Sample Name',
                'email' => 'sample@example.com',
                'password' => 'password123', // Automatically hashed via cast
                'user_type' => 'staff',
                'is_active' => true,
                'can_login' => true,
            ]
        );

        // Assign Super Admin role to user01
        $user->assignRole('Super Admin');
    }
}
