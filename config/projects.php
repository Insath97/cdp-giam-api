<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Approved Downstream Project Registry
    |--------------------------------------------------------------------------
    |
    | Canonical definitions of all downstream projects approved to integrate
    | with GIAM. This registry is the single source of truth consumed by:
    |   1. Operational single-project onboarding (giam:onboard-project {code})
    |   2. Fresh-environment / CI bootstrap seeding (ProjectRegistrySeeder)
    |
    | SECURITY RULE:
    | This file must NEVER contain direct plaintext credentials. Any project
    | requiring credentials specifies config reference keys pointing to
    | environment-backed configuration in config/services.php.
    |
    */

    'definitions' => [

        'hrms' => [
            'name' => 'HRMS Portal',
            'description' => 'Human Resource Management and Attendance System',
            'base_url' => 'http://localhost:8001',
            'icon_url' => '/icons/hrms.svg',
            'status' => 'active',
            'integration' => [
                'api_base_url_fallback' => 'http://localhost:8001/api/giam/integration',
                'api_url_config_key' => 'services.hrms.api_url',
                'auth_method' => 'bearer_token',
                'client_id_config_key' => 'services.hrms.client_id',
                'client_secret_config_key' => 'services.hrms.client_secret',
                'required_credentials' => [
                    'client_id' => 'HRMS_CLIENT_ID',
                    'client_secret' => 'HRMS_CLIENT_SECRET',
                ],
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
            ],
        ],

        'centrix' => [
            'name' => 'CENTRIX Logistics',
            'description' => 'Fleet Logistics, Freight Tracking and Operations Portal',
            'base_url_config_key' => 'services.centrix.frontend_url',
            'base_url_fallback' => 'http://localhost:3001',
            'icon_url' => '/icons/centrix.svg',
            'status' => 'active',
            'integration' => [
                'api_base_url_fallback' => 'http://localhost:8002/api/giam/integration',
                'api_url_config_key' => 'services.centrix.api_url',
                'auth_method' => 'bearer_token',
                'client_id_config_key' => 'services.centrix.client_id',
                'client_secret_config_key' => 'services.centrix.client_secret',
                'required_credentials' => [
                    'client_id' => 'CENTRIX_CLIENT_ID',
                    'client_secret' => 'CENTRIX_CLIENT_SECRET',
                ],
                'redirect_uris_patterns' => [
                    '{base_url}/sso/callback',
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
            ],
        ],

        'payroll' => [
            'name' => 'Payroll System',
            'description' => 'Corporate Payroll, Compensation and Benefits System',
            'base_url' => 'http://localhost:8003',
            'icon_url' => '/icons/payroll.svg',
            'status' => 'active',
            'integration' => [
                'api_base_url_fallback' => 'http://localhost:8003/api/giam/integration',
                'api_url_config_key' => 'services.payroll.api_url',
                'auth_method' => 'bearer_token',
                'client_id_config_key' => 'services.payroll.client_id',
                'client_secret_config_key' => 'services.payroll.client_secret',
                'required_credentials' => [
                    'client_id' => 'PAYROLL_CLIENT_ID',
                    'client_secret' => 'PAYROLL_CLIENT_SECRET',
                ],
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
            ],
        ],

        'stockly' => [
            'name' => 'Stockly',
            'description' => 'Stockly Inventory and Supply Chain Management System',
            'base_url_config_key' => 'services.stockly.base_url',
            'base_url_fallback' => '',
            'icon_url' => '/icons/stockly.svg',
            'status' => 'active',
            'integration' => [
                'api_base_url_fallback' => '',
                'api_url_config_key' => 'services.stockly.api_url',
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
            ],
        ],

        'credix' => [
            'name' => 'CrediX',
            'description' => 'CrediX Lending and Pawning Portal',
            'base_url' => '',
            'icon_url' => '/icons/credix.svg',
            'status' => 'active',
            'integration' => [
                'api_base_url' => '',
                'auth_method' => 'api_key',
                'client_id' => null,
                'allowed_user_fields' => [
                    'employee_code',
                    'full_name',
                    'id_type',
                    'id_number',
                    'phone_primary',
                ],
                'allowed_resources' => [
                    'employees:read',
                ],
                'allowed_resource_fields' => [
                    'employees' => [
                        'employee_code',
                        'full_name',
                        'id_type',
                        'id_number',
                        'phone_primary',
                    ],
                ],
                'sync_enabled' => false,
                'sso_enabled' => false,
                'status' => 'healthy',
            ],
        ],

    ],

];
