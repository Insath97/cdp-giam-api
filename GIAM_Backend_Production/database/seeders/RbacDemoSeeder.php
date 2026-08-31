<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Application;
use App\Models\Module;
use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use App\Models\PermissionGroup;
use Illuminate\Support\Facades\DB;

class RbacDemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = bcrypt(env('GIAM_SEED_PASSWORD', 'Admin@1234'));

        // ─── 1. Fetch Applications ────────────────────────────────────────────
        $credix = Application::where('code', 'credix')->first();
        $hrms   = Application::where('code', 'hrms')->first();
        $orbit  = Application::where('code', 'orbit')->first();

        // ─── 2. Extra Modules ─────────────────────────────────────────────────
        $creditCards = Module::updateOrCreate(
            ['application_id' => $credix->id, 'code' => 'credit_cards'],
            ['name' => 'Credit Cards', 'description' => 'Credit card management']
        );
        $pawning = Module::updateOrCreate(
            ['application_id' => $credix->id, 'code' => 'pawning'],
            ['name' => 'Pawning', 'description' => 'Pawning & gold loan module']
        );
        $payroll = Module::updateOrCreate(
            ['application_id' => $hrms->id, 'code' => 'payroll'],
            ['name' => 'Payroll', 'description' => 'Payroll processing']
        );
        $recruitment = Module::updateOrCreate(
            ['application_id' => $hrms->id, 'code' => 'recruitment'],
            ['name' => 'Recruitment', 'description' => 'Recruitment management']
        );
        $inventory = Module::updateOrCreate(
            ['application_id' => $orbit->id, 'code' => 'inventory'],
            ['name' => 'Inventory', 'description' => 'Inventory management']
        );
        $sales = Module::updateOrCreate(
            ['application_id' => $orbit->id, 'code' => 'sales'],
            ['name' => 'Sales', 'description' => 'Sales management']
        );

        // Fetch existing modules
        $customers   = Module::where('application_id', $credix->id)->where('code', 'customers')->first();
        $loans       = Module::where('application_id', $credix->id)->where('code', 'loans')->first();
        $employees   = Module::where('application_id', $hrms->id)->where('code', 'employees')->first();

        // ─── 3. Extra Permissions ─────────────────────────────────────────────
        $credixPerms = [];
        foreach ([
            ['customer.view',       'Credix Customers', $customers],
            ['customer.create',     'Credix Customers', $customers],
            ['customer.edit',       'Credix Customers', $customers],
            ['customer.delete',     'Credix Customers', $customers],
            ['loan.view',           'Credix Loans',     $loans],
            ['loan.create',         'Credix Loans',     $loans],
            ['loan.approve',        'Credix Loans',     $loans],
            ['loan.reject',         'Credix Loans',     $loans],
            ['loan.disburse',       'Credix Loans',     $loans],
            ['card.view',           'Credix Cards',     $creditCards],
            ['card.issue',          'Credix Cards',     $creditCards],
            ['card.block',          'Credix Cards',     $creditCards],
            ['pawning.view',        'Credix Pawning',   $pawning],
            ['pawning.create',      'Credix Pawning',   $pawning],
            ['pawning.approve',     'Credix Pawning',   $pawning],
        ] as [$name, $group, $module]) {
            $credixPerms[$name] = Permission::updateOrCreate(
                ['name' => $name, 'guard_name' => 'api'],
                ['group_name' => $group, 'module_id' => $module?->id, 'application_id' => $credix->id]
            );
        }

        $hrmsPerms = [];
        foreach ([
            ['employee.view',       'HRMS Employees',    $employees],
            ['employee.create',     'HRMS Employees',    $employees],
            ['employee.edit',       'HRMS Employees',    $employees],
            ['employee.delete',     'HRMS Employees',    $employees],
            ['payroll.view',        'HRMS Payroll',      $payroll],
            ['payroll.process',     'HRMS Payroll',      $payroll],
            ['payroll.approve',     'HRMS Payroll',      $payroll],
            ['recruitment.view',    'HRMS Recruitment',  $recruitment],
            ['recruitment.create',  'HRMS Recruitment',  $recruitment],
            ['recruitment.approve', 'HRMS Recruitment',  $recruitment],
        ] as [$name, $group, $module]) {
            $hrmsPerms[$name] = Permission::updateOrCreate(
                ['name' => $name, 'guard_name' => 'api'],
                ['group_name' => $group, 'module_id' => $module?->id, 'application_id' => $hrms->id]
            );
        }

        $orbitPerms = [];
        foreach ([
            ['inventory.view',   'Orbit Inventory', $inventory],
            ['inventory.manage', 'Orbit Inventory', $inventory],
            ['sales.view',       'Orbit Sales',     $sales],
            ['sales.create',     'Orbit Sales',     $sales],
            ['sales.approve',    'Orbit Sales',     $sales],
        ] as [$name, $group, $module]) {
            $orbitPerms[$name] = Permission::updateOrCreate(
                ['name' => $name, 'guard_name' => 'api'],
                ['group_name' => $group, 'module_id' => $module?->id, 'application_id' => $orbit->id]
            );
        }

        // ─── 4. Permission Groups ─────────────────────────────────────────────
        $pgCredixReadOnly = PermissionGroup::updateOrCreate(
            ['name' => 'Credix Read-Only', 'application_id' => $credix->id],
            ['description' => 'View-only access across Credix modules']
        );
        $pgCredixReadOnly->permissions()->sync([
            $credixPerms['customer.view']->id,
            $credixPerms['loan.view']->id,
            $credixPerms['card.view']->id,
            $credixPerms['pawning.view']->id,
        ]);

        $pgCredixOfficer = PermissionGroup::updateOrCreate(
            ['name' => 'Credix Loan Officer', 'application_id' => $credix->id],
            ['description' => 'Full loan and customer management']
        );
        $pgCredixOfficer->permissions()->sync([
            $credixPerms['customer.view']->id,
            $credixPerms['customer.create']->id,
            $credixPerms['customer.edit']->id,
            $credixPerms['loan.view']->id,
            $credixPerms['loan.create']->id,
            $credixPerms['loan.approve']->id,
        ]);

        $pgHrmsReadOnly = PermissionGroup::updateOrCreate(
            ['name' => 'HRMS Read-Only', 'application_id' => $hrms->id],
            ['description' => 'View-only access in HRMS']
        );
        $pgHrmsReadOnly->permissions()->sync([
            $hrmsPerms['employee.view']->id,
            $hrmsPerms['payroll.view']->id,
            $hrmsPerms['recruitment.view']->id,
        ]);

        $pgHrManager = PermissionGroup::updateOrCreate(
            ['name' => 'HR Manager', 'application_id' => $hrms->id],
            ['description' => 'Full HR management access']
        );
        $pgHrManager->permissions()->sync([
            $hrmsPerms['employee.view']->id,
            $hrmsPerms['employee.create']->id,
            $hrmsPerms['employee.edit']->id,
            $hrmsPerms['payroll.view']->id,
            $hrmsPerms['payroll.process']->id,
            $hrmsPerms['payroll.approve']->id,
            $hrmsPerms['recruitment.view']->id,
            $hrmsPerms['recruitment.create']->id,
            $hrmsPerms['recruitment.approve']->id,
        ]);

        $pgOrbitSales = PermissionGroup::updateOrCreate(
            ['name' => 'Orbit Sales Team', 'application_id' => $orbit->id],
            ['description' => 'Sales and inventory access in Orbit']
        );
        $pgOrbitSales->permissions()->sync([
            $orbitPerms['inventory.view']->id,
            $orbitPerms['sales.view']->id,
            $orbitPerms['sales.create']->id,
        ]);

        // ─── 5. Roles ─────────────────────────────────────────────────────────

        // GIAM Internal Roles (application_id = NULL)
        $rStaff = Role::updateOrCreate(
            ['name' => 'Staff', 'guard_name' => 'api', 'application_id' => null]
        );
        $rHr = Role::updateOrCreate(
            ['name' => 'HR', 'guard_name' => 'api', 'application_id' => null]
        );

        // GIAM Internal permissions for HR role
        $hrInternalPerms = \Spatie\Permission\Models\Permission::whereNull('application_id')
            ->whereIn('name', ['User Index', 'User Create', 'User Update', 'Role Index'])
            ->get();
        $rHr->syncPermissions($hrInternalPerms);

        // Credix Roles
        $rLoanOfficer = Role::updateOrCreate(
            ['name' => 'Loan Officer', 'guard_name' => 'api', 'application_id' => $credix->id]
        );
        $rLoanOfficer->syncPermissions([
            $credixPerms['customer.view'],
            $credixPerms['customer.create'],
            $credixPerms['customer.edit'],
            $credixPerms['loan.view'],
            $credixPerms['loan.create'],
            $credixPerms['loan.approve'],
        ]);

        $rBranchManager = Role::updateOrCreate(
            ['name' => 'Branch Manager', 'guard_name' => 'api', 'application_id' => $credix->id]
        );
        $rBranchManager->syncPermissions(array_values($credixPerms));

        $rAuditor = Role::updateOrCreate(
            ['name' => 'Auditor', 'guard_name' => 'api', 'application_id' => $credix->id]
        );
        $rAuditor->syncPermissions([
            $credixPerms['customer.view'],
            $credixPerms['loan.view'],
            $credixPerms['card.view'],
            $credixPerms['pawning.view'],
        ]);

        // HRMS Roles
        $rHrManager = Role::updateOrCreate(
            ['name' => 'HR Manager', 'guard_name' => 'api', 'application_id' => $hrms->id]
        );
        $rHrManager->syncPermissions(array_values($hrmsPerms));

        $rHrExecutive = Role::updateOrCreate(
            ['name' => 'HR Executive', 'guard_name' => 'api', 'application_id' => $hrms->id]
        );
        $rHrExecutive->syncPermissions([
            $hrmsPerms['employee.view'],
            $hrmsPerms['employee.create'],
            $hrmsPerms['recruitment.view'],
            $hrmsPerms['recruitment.create'],
        ]);

        // Orbit Roles
        $rSalesManager = Role::updateOrCreate(
            ['name' => 'Sales Manager', 'guard_name' => 'api', 'application_id' => $orbit->id]
        );
        $rSalesManager->syncPermissions(array_values($orbitPerms));

        // ─── 6. Users ─────────────────────────────────────────────────────────

        // Admin user (already exists, just make sure Super Admin role assigned)
        $admin = User::where('email', 'dev@localhost.com')->first();
        if ($admin) {
            $superAdmin = Role::where('name', 'Super Admin')->whereNull('application_id')->first();
            if ($superAdmin) {
                $admin->assignRole($superAdmin);
            }
        }

        // Staff User → has access to Credix as Loan Officer
        $staffUser = User::updateOrCreate(
            ['email' => 'staff@giam.local'],
            [
                'name'      => 'John Staff',
                'username'  => 'jstaff',
                'password'  => $password,
                'user_type' => 'staff',
                'is_active' => true,
                'can_login' => true,
            ]
        );
        $staffUser->syncRoles([$rStaff]);
        $staffUser->applications()->syncWithoutDetaching([$credix->id]);
        $staffUser->syncRolesForApplication([$rLoanOfficer->id], $credix->id);
        // Assign permission group
        DB::table('model_has_permission_groups')->updateOrInsert(
            ['model_id' => $staffUser->id, 'model_type' => User::class, 'permission_group_id' => $pgCredixOfficer->id],
        );

        // HR User → has HR role, access to HRMS as HR Manager
        $hrUser = User::updateOrCreate(
            ['email' => 'hr@giam.local'],
            [
                'name'      => 'Sara HR',
                'username'  => 'srahr',
                'password'  => $password,
                'user_type' => 'staff',
                'is_active' => true,
                'can_login' => true,
            ]
        );
        $hrUser->syncRoles([$rHr]);
        $hrUser->applications()->syncWithoutDetaching([$hrms->id, $credix->id]);
        $hrUser->syncRolesForApplication([$rHrManager->id], $hrms->id);
        $hrUser->syncRolesForApplication([$rAuditor->id], $credix->id);
        DB::table('model_has_permission_groups')->updateOrInsert(
            ['model_id' => $hrUser->id, 'model_type' => User::class, 'permission_group_id' => $pgHrManager->id],
        );
        DB::table('model_has_permission_groups')->updateOrInsert(
            ['model_id' => $hrUser->id, 'model_type' => User::class, 'permission_group_id' => $pgCredixReadOnly->id],
        );

        // Mohamed (from CdpEmpireSeeder) — enhance with extra roles
        $mohamed = User::where('email', 'mohamed@cdp.lk')->first();
        if ($mohamed) {
            $mohamed->applications()->syncWithoutDetaching([$orbit->id]);
            $mohamed->syncRolesForApplication([$rSalesManager->id], $orbit->id);
        }

        $this->command->info('✅ RBAC Demo seeder completed!');
        $this->command->info('   Staff user  → staff@giam.local  / Admin@1234 → Credix (Loan Officer)');
        $this->command->info('   HR user     → hr@giam.local     / Admin@1234 → HRMS (HR Manager) + Credix (Auditor)');
        $this->command->info('   Admin user  → dev@localhost.com / Admin@1234 → Super Admin (all access)');
    }
}
