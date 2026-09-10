<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectIntegration;
use Illuminate\Database\Seeder;

class ProjectRegistrySeeder extends Seeder
{
    public function run(): void
    {
        // 1. HRMS Project
        $hrmsApiUrl = config('services.hrms.api_url') ?: 'http://localhost:8001/api/giam/integration';

        $hrmsClientId = config('services.hrms.client_id');
        if (empty($hrmsClientId) || trim((string) $hrmsClientId) === '') {
            throw new \RuntimeException('Missing required integration credential: [HRMS_CLIENT_ID]');
        }

        $hrmsSecret = config('services.hrms.client_secret');
        if (empty($hrmsSecret) || trim((string) $hrmsSecret) === '') {
            throw new \RuntimeException('Missing required integration credential: [HRMS_CLIENT_SECRET]');
        }

        $hrms = Project::updateOrCreate(
            ['code' => 'hrms'],
            [
                'name' => 'HRMS Portal',
                'description' => 'Human Resource Management and Attendance System',
                'base_url' => 'http://localhost:8001',
                'icon_url' => '/icons/hrms.svg',
                'status' => 'active',
            ]
        );

        ProjectIntegration::updateOrCreate(
            ['project_id' => $hrms->id],
            [
                'api_base_url' => $hrmsApiUrl,
                'auth_method' => 'bearer_token',
                'client_id' => trim((string) $hrmsClientId),
                'client_secret' => trim((string) $hrmsSecret),
                'allowed_user_fields' => [
                    'employee_code',
                    'f_name',
                    'l_name',
                    'full_name',
                    'name_with_initials',
                    'id_type',
                    'id_number',
                    'date_of_birth',
                    'email',
                    'phone_primary',
                    'address_line_1',
                    'department_code',
                    'designation_code',
                    'reporting_manager_code',
                ],
                'sync_enabled' => true,
                'sso_enabled' => true,
                'status' => 'healthy',
            ]
        );

        $centrixFrontendUrl = rtrim((string) (config('services.centrix.frontend_url') ?: 'http://localhost:3001'), '/');

        // 2. CENTRIX Project (Strictly excludes PII such as id_number, date_of_birth, address)
        $centrix = Project::updateOrCreate(
            ['code' => 'centrix'],
            [
                'name' => 'CENTRIX Logistics',
                'description' => 'Fleet Logistics, Freight Tracking and Operations Portal',
                'base_url' => $centrixFrontendUrl,
                'icon_url' => '/icons/centrix.svg',
                'status' => 'active',
            ]
        );

        $clientId = config('services.centrix.client_id');
        if (empty($clientId) || trim((string) $clientId) === '') {
            throw new \RuntimeException('Missing required integration credential: [CENTRIX_CLIENT_ID]');
        }

        $centrixSecret = config('services.centrix.client_secret');
        if (empty($centrixSecret) || trim((string) $centrixSecret) === '') {
            throw new \RuntimeException('Missing required integration credential: [CENTRIX_CLIENT_SECRET]');
        }

        $centrixApiUrl = config('services.centrix.api_url') ?: 'http://localhost:8002/api/giam/integration';

        ProjectIntegration::updateOrCreate(
            ['project_id' => $centrix->id],
            [
                'api_base_url' => $centrixApiUrl,
                'auth_method' => 'bearer_token',
                'client_id' => trim((string) $clientId),
                'client_secret' => trim((string) $centrixSecret),
                'redirect_uris' => [
                    "{$centrixFrontendUrl}/sso/callback",
                    'http://127.0.0.1:3001/sso/callback',
                ],
                'allowed_user_fields' => [
                    'employee_code',
                    'f_name',
                    'l_name',
                    'full_name',
                    'email',
                    'phone_primary',
                    'department_code',
                    'designation_code',
                ],
                'sync_enabled' => true,
                'sso_enabled' => true,
                'status' => 'healthy',
            ]
        );

        // 3. Payroll Project
        $payrollApiUrl = config('services.payroll.api_url') ?: 'http://localhost:8003/api/giam/integration';

        $payrollClientId = config('services.payroll.client_id');
        if (empty($payrollClientId) || trim((string) $payrollClientId) === '') {
            throw new \RuntimeException('Missing required integration credential: [PAYROLL_CLIENT_ID]');
        }

        $payrollSecret = config('services.payroll.client_secret');
        if (empty($payrollSecret) || trim((string) $payrollSecret) === '') {
            throw new \RuntimeException('Missing required integration credential: [PAYROLL_CLIENT_SECRET]');
        }

        $payroll = Project::updateOrCreate(
            ['code' => 'payroll'],
            [
                'name' => 'Payroll System',
                'description' => 'Corporate Payroll, Compensation and Benefits System',
                'base_url' => 'http://localhost:8003',
                'icon_url' => '/icons/payroll.svg',
                'status' => 'active',
            ]
        );

        ProjectIntegration::updateOrCreate(
            ['project_id' => $payroll->id],
            [
                'api_base_url' => $payrollApiUrl,
                'auth_method' => 'bearer_token',
                'client_id' => trim((string) $payrollClientId),
                'client_secret' => trim((string) $payrollSecret),
                'allowed_user_fields' => [
                    'employee_code',
                    'f_name',
                    'l_name',
                    'full_name',
                    'name_with_initials',
                    'id_type',
                    'id_number',
                    'date_of_birth',
                    'email',
                    'phone_primary',
                    'address_line_1',
                    'department_code',
                    'designation_code',
                ],
                'sync_enabled' => true,
                'sso_enabled' => true,
                'status' => 'healthy',
            ]
        );

        // 4. STOCKLY Project (External Inventory Application - Resource API Consumer)
        $stocklyBaseUrl = (string) (config('services.stockly.base_url') ?? '');
        $stocklyApiUrl = (string) (config('services.stockly.api_url') ?? '');

        $stockly = Project::updateOrCreate(
            ['code' => 'stockly'],
            [
                'name' => 'Stockly',
                'description' => 'Stockly Inventory and Supply Chain Management System',
                'base_url' => $stocklyBaseUrl,
                'icon_url' => '/icons/stockly.svg',
                'status' => 'active',
            ]
        );

        ProjectIntegration::updateOrCreate(
            ['project_id' => $stockly->id],
            [
                'api_base_url' => $stocklyApiUrl,
                'auth_method' => 'api_key',
                'client_id' => null,
                'allowed_user_fields' => [
                    'employee_code',
                    'full_name',
                    'email',
                    'department_code',
                    'designation_code',
                    'branch_code',
                ],
                'allowed_resources' => [
                    'employees:read',
                    'departments:read',
                    'designations:read',
                    'branches:read',
                    'regions:read',
                    'zones:read',
                    'provinces:read',
                ],
                'allowed_resource_fields' => [
                    'employees' => [
                        'employee_code',
                        'full_name',
                        'email',
                        'department_code',
                        'designation_code',
                        'branch_code',
                        'region_code',
                        'zonal_code',
                        'province_code',
                        'department',
                        'designation',
                        'branch',
                        'region',
                        'zone',
                        'province',
                    ],
                    'departments' => [
                        'code',
                        'name',
                    ],
                    'designations' => [
                        'code',
                        'name',
                        'department_code',
                    ],
                    'branches' => [
                        'code',
                        'name',
                        'city',
                        'region_code',
                    ],
                    'regions' => [
                        'code',
                        'name',
                        'zonal_code',
                    ],
                    'zones' => [
                        'code',
                        'name',
                        'province_code',
                    ],
                    'provinces' => [
                        'code',
                        'name',
                    ],
                ],
                'sync_enabled' => false,
                'sso_enabled' => false,
                'status' => 'healthy',
            ]
        );
    }
}
