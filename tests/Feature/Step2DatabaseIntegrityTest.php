<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ProjectRegistrySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Step2DatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /**
     * Test A: Employee can exist without an associated User record.
     */
    public function test_employee_can_exist_without_user(): void
    {
        $employee = Employee::create([
            'employee_code' => 'EMP2001',
            'f_name' => 'Charlie',
            'l_name' => 'Brown',
            'full_name' => 'Charlie Brown',
            'name_with_initials' => 'C. Brown',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '981234567V',
            'date_of_birth' => '1998-02-02',
            'email' => 'charlie@cdp.lk',
            'phone' => '+94112223344',
            'address_line_1' => 'Peanuts Road',
            'city' => 'Colombo',
            'phone_primary' => '+94771234567',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->assertDatabaseHas('employees', ['employee_code' => 'EMP2001']);
        $this->assertNull($employee->user);
    }

    /**
     * Test B: User linked to an existing employee_code succeeds.
     */
    public function test_user_linked_to_existing_employee_code_succeeds(): void
    {
        Employee::create([
            'employee_code' => 'EMP2002',
            'f_name' => 'Dana',
            'l_name' => 'Scully',
            'full_name' => 'Dana Scully',
            'name_with_initials' => 'D. Scully',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '981234568V',
            'date_of_birth' => '1998-03-03',
            'email' => 'dana@cdp.lk',
            'phone' => '+94112223345',
            'address_line_1' => 'FBI Way',
            'city' => 'Colombo',
            'phone_primary' => '+94771234568',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $user = User::create([
            'employee_code' => 'EMP2002',
            'name' => 'Dana Scully',
            'username' => 'scully2002',
            'email' => 'dana@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'employee_code' => 'EMP2002',
            'username' => 'scully2002',
        ]);
    }

    /**
     * Test C: User linked to nonexistent employee_code is rejected by database foreign key constraint.
     */
    public function test_user_linked_to_nonexistent_employee_code_fails_fk_constraint(): void
    {
        $this->expectException(QueryException::class);

        // Attempt to insert user referencing nonexistent employee code
        User::create([
            'employee_code' => 'EMP_NONEXISTENT_9999',
            'name' => 'Ghost User',
            'username' => 'ghost_user',
            'email' => 'ghost@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
    }

    /**
     * Test D: Duplicate employees.employee_code is rejected by unique constraint.
     */
    public function test_duplicate_employee_code_on_employees_fails(): void
    {
        Employee::create([
            'employee_code' => 'EMP2003',
            'f_name' => 'Fox',
            'l_name' => 'Mulder',
            'full_name' => 'Fox Mulder',
            'name_with_initials' => 'F. Mulder',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '981234569V',
            'date_of_birth' => '1998-04-04',
            'email' => 'mulder@cdp.lk',
            'phone' => '+94112223346',
            'address_line_1' => 'X-Files St',
            'city' => 'Colombo',
            'phone_primary' => '+94771234569',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $this->expectException(QueryException::class);

        // Duplicate employee_code
        Employee::create([
            'employee_code' => 'EMP2003',
            'f_name' => 'Duplicate',
            'l_name' => 'User',
            'full_name' => 'Duplicate User',
            'name_with_initials' => 'D. User',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '981234570V',
            'date_of_birth' => '1998-05-05',
            'email' => 'dup@cdp.lk',
            'phone' => '+94112223347',
            'address_line_1' => 'Dup St',
            'city' => 'Colombo',
            'phone_primary' => '+94771234570',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);
    }

    /**
     * Test E: Duplicate users.employee_code is rejected by unique constraint.
     */
    public function test_duplicate_employee_code_on_users_fails(): void
    {
        Employee::create([
            'employee_code' => 'EMP2004',
            'f_name' => 'Walter',
            'l_name' => 'Skinner',
            'full_name' => 'Walter Skinner',
            'name_with_initials' => 'W. Skinner',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '981234571V',
            'date_of_birth' => '1998-06-06',
            'email' => 'skinner@cdp.lk',
            'phone' => '+94112223348',
            'address_line_1' => 'Bureau Ave',
            'city' => 'Colombo',
            'phone_primary' => '+94771234571',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        User::create([
            'employee_code' => 'EMP2004',
            'name' => 'Walter Skinner',
            'username' => 'skinner2004',
            'email' => 'skinner@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $this->expectException(QueryException::class);

        // Second user trying to attach to EMP2004
        User::create([
            'employee_code' => 'EMP2004',
            'name' => 'Second User',
            'username' => 'second_user',
            'email' => 'second@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);
    }

    /**
     * Test F: Deleting a linked Employee is prevented by ON DELETE RESTRICT.
     */
    public function test_deleting_linked_employee_is_prevented_by_restrict(): void
    {
        $employee = Employee::create([
            'employee_code' => 'EMP2005',
            'f_name' => 'John',
            'l_name' => 'Doggett',
            'full_name' => 'John Doggett',
            'name_with_initials' => 'J. Doggett',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '981234572V',
            'date_of_birth' => '1998-07-07',
            'email' => 'doggett@cdp.lk',
            'phone' => '+94112223349',
            'address_line_1' => 'Agent Way',
            'city' => 'Colombo',
            'phone_primary' => '+94771234572',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        User::create([
            'employee_code' => 'EMP2005',
            'name' => 'John Doggett',
            'username' => 'doggett2005',
            'email' => 'doggett@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $this->expectException(QueryException::class);

        // Attempt raw delete on employees while user references it
        DB::table('employees')->where('id', $employee->id)->delete();
    }

    /**
     * Test G: Updating employee_code of a linked Employee is prevented by ON UPDATE RESTRICT.
     */
    public function test_updating_linked_employee_code_is_prevented_by_restrict(): void
    {
        Employee::create([
            'employee_code' => 'EMP2006',
            'f_name' => 'Monica',
            'l_name' => 'Reyes',
            'full_name' => 'Monica Reyes',
            'name_with_initials' => 'M. Reyes',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '981234573V',
            'date_of_birth' => '1998-08-08',
            'email' => 'reyes@cdp.lk',
            'phone' => '+94112223350',
            'address_line_1' => 'Agent Blvd',
            'city' => 'Colombo',
            'phone_primary' => '+94771234573',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        User::create([
            'employee_code' => 'EMP2006',
            'name' => 'Monica Reyes',
            'username' => 'reyes2006',
            'email' => 'reyes@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        $this->expectException(QueryException::class);

        // Attempt raw update on employee_code while user references it
        DB::table('employees')->where('employee_code', 'EMP2006')->update(['employee_code' => 'EMP_MUTATED']);
    }

    /**
     * Test H & I: Eloquent User -> Employee and Employee -> User relationships work bidirectionally.
     */
    public function test_bidirectional_eloquent_relationships(): void
    {
        $employee = Employee::create([
            'employee_code' => 'EMP2007',
            'f_name' => 'Alex',
            'l_name' => 'Krycek',
            'full_name' => 'Alex Krycek',
            'name_with_initials' => 'A. Krycek',
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => '981234574V',
            'date_of_birth' => '1998-09-09',
            'email' => 'krycek@cdp.lk',
            'phone' => '+94112223351',
            'address_line_1' => 'Shadow St',
            'city' => 'Colombo',
            'phone_primary' => '+94771234574',
            'start_date' => '2026-01-01',
            'province_code' => 'WP',
            'zonal_code' => 'Z01',
            'region_code' => 'R01',
            'department_code' => 'DEP01',
            'designation_code' => 'DES01',
        ]);

        $user = User::create([
            'employee_code' => 'EMP2007',
            'name' => 'Alex Krycek',
            'username' => 'krycek2007',
            'email' => 'krycek@cdp.lk',
            'password' => 'secret123',
            'user_type' => 'staff',
            'is_active' => true,
            'can_login' => true,
        ]);

        // User -> Employee
        $this->assertNotNull($user->employee);
        $this->assertEquals('EMP2007', $user->employee->employee_code);
        $this->assertEquals('Alex Krycek', $user->employee->full_name);

        // Employee -> User
        $this->assertNotNull($employee->user);
        $this->assertEquals('EMP2007', $employee->user->employee_code);
        $this->assertEquals('krycek2007', $employee->user->username);
    }

    /**
     * Test J: Seeder fails fast when CENTRIX_CLIENT_SECRET is missing.
     */
    public function test_project_registry_seeder_fails_fast_when_centrix_secret_missing(): void
    {
        // Temporarily clear configuration
        Config::set('services.centrix.client_secret', null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required integration credential: [CENTRIX_CLIENT_SECRET]');

        $seeder = new ProjectRegistrySeeder();
        $seeder->run();
    }
}
