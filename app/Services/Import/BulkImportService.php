<?php

namespace App\Services\Import;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLoggerService;
use App\Services\Rbac\PermissionGrantAuthorityService;
use App\Services\User\UserCreationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Service orchestrating batch employee and user principal ingestion from CSV templates,
 * row-level validation, atomic persistence, and initial credential generation.
 */
class BulkImportService
{
    public function __construct(
        protected AuditLoggerService $auditLogger,
        protected UserCreationService $userCreationService,
        protected PermissionGrantAuthorityService $grantAuthorityService
    ) {}

    /**
     * Plain column headers for Employee CSV Template.
     *
     * @return list<string>
     */
    public function getEmployeeTemplateHeaders(): array
    {
        return [
            'employee_code',
            'f_name',
            'l_name',
            'full_name',
            'name_with_initials',
            'employee_type',
            'id_type',
            'id_number',
            'date_of_birth',
            'email',
            'phone',
            'address_line_1',
            'city',
            'country',
            'phone_primary',
            'province_code',
            'zonal_code',
            'region_code',
            'department_code',
            'designation_code',
            'start_date',
        ];
    }

    /**
     * Plain column headers for User CSV Template (omits plaintext password column).
     *
     * @return list<string>
     */
    public function getUserTemplateHeaders(): array
    {
        return [
            'employee_code',
            'name',
            'username',
            'email',
            'user_type',
            'is_active',
            'can_login',
            'role',
        ];
    }

    /**
     * Process and ingest an uploaded Employee CSV file.
     *
     * @param UploadedFile $file
     * @param User $actor
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function importEmployees(UploadedFile $file, User $actor): array
    {
        $this->validateFile($file);

        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new HttpException(422, 'Unable to open uploaded CSV file.');
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);
            throw new HttpException(422, 'Uploaded CSV file is empty.');
        }

        // Clean UTF-8 BOM if present
        $header[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header[0]);
        $header = array_map('trim', $header);

        $expected = $this->getEmployeeTemplateHeaders();

        // Detect wrong template: User/Principal CSV uploaded to Employee import
        if (in_array('username', $header) && (in_array('role', $header) || in_array('user_type', $header))) {
            fclose($handle);
            throw new HttpException(422, 'This appears to be a GIAM User import file. Please use Users & Directory → Bulk Create GIAM Accounts.');
        }

        $missing = array_diff($expected, $header);
        if (! empty($missing)) {
            fclose($handle);
            throw new HttpException(422, 'Missing required CSV columns: ' . implode(', ', $missing));
        }

        $rowIndex = 1;
        $successful = 0;
        $failed = 0;
        $errors = [];

        // Pre-load active uniqueness and canonical reference sets into memory to eliminate per-row SELECT queries
        $existingEmployeeCodes = array_flip(Employee::withTrashed()->pluck('employee_code')->toArray());
        $existingIdNumbers = array_flip(Employee::withTrashed()->whereNotNull('id_number')->pluck('id_number')->toArray());
        $validDepartments = array_flip(\App\Models\OrgDepartment::pluck('code')->toArray());
        $validDesignations = array_flip(\App\Models\OrgDesignation::pluck('code')->toArray());
        $validProvinces = array_flip(\App\Models\OrgProvince::pluck('code')->toArray());
        $validZones = array_flip(\App\Models\OrgZone::pluck('code')->toArray());
        $validRegions = array_flip(\App\Models\OrgRegion::pluck('code')->toArray());
        $validBranches = array_flip(\App\Models\OrgBranch::pluck('code')->toArray());

        while (($row = fgetcsv($handle)) !== false) {
            $rowIndex++;
            if (empty(array_filter($row))) {
                continue; // Skip empty rows
            }

            if (count($row) !== count($header)) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => null,
                    'reason' => 'Row column count does not match CSV header count.',
                    'errors' => ['Row column count does not match CSV header count.'],
                ];
                continue;
            }

            $data = array_combine($header, array_map('trim', $row));

            // In-batch and database uniqueness checks in memory
            if (isset($seenEmployeeCodes[$data['employee_code']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'reason' => "Duplicate employee_code '{$data['employee_code']}' inside CSV file.",
                    'errors' => ["Duplicate employee_code '{$data['employee_code']}' inside CSV file."],
                ];
                continue;
            }
            $seenEmployeeCodes[$data['employee_code']] = true;

            if (isset($existingEmployeeCodes[$data['employee_code']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'reason' => "The employee code '{$data['employee_code']}' has already been taken.",
                    'errors' => ["The employee code '{$data['employee_code']}' has already been taken."],
                ];
                continue;
            }

            if (! empty($data['id_number'])) {
                if (isset($seenIdNumbers[$data['id_number']])) {
                    $failed++;
                    $errors[] = [
                        'row' => $rowIndex,
                        'employee_code' => $data['employee_code'] ?? null,
                        'reason' => "Duplicate id_number '{$data['id_number']}' inside CSV file.",
                        'errors' => ["Duplicate id_number '{$data['id_number']}' inside CSV file."],
                    ];
                    continue;
                }
                $seenIdNumbers[$data['id_number']] = true;

                if (isset($existingIdNumbers[$data['id_number']])) {
                    $failed++;
                    $errors[] = [
                        'row' => $rowIndex,
                        'employee_code' => $data['employee_code'] ?? null,
                        'reason' => "The id number '{$data['id_number']}' has already been taken.",
                        'errors' => ["The id number '{$data['id_number']}' has already been taken."],
                    ];
                    continue;
                }
            }

            // In-memory canonical organizational reference checks (matching StoreEmployeeRequest)
            if (! isset($validDepartments[$data['department_code']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'reason' => "The selected department code '{$data['department_code']}' is invalid.",
                    'errors' => ["The selected department code '{$data['department_code']}' is invalid."],
                ];
                continue;
            }

            if (! isset($validDesignations[$data['designation_code']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'reason' => "The selected designation code '{$data['designation_code']}' is invalid.",
                    'errors' => ["The selected designation code '{$data['designation_code']}' is invalid."],
                ];
                continue;
            }

            if (! isset($validProvinces[$data['province_code']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'reason' => "The selected province code '{$data['province_code']}' is invalid.",
                    'errors' => ["The selected province code '{$data['province_code']}' is invalid."],
                ];
                continue;
            }

            if (! isset($validZones[$data['zonal_code']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'reason' => "The selected zonal code '{$data['zonal_code']}' is invalid.",
                    'errors' => ["The selected zonal code '{$data['zonal_code']}' is invalid."],
                ];
                continue;
            }

            if (! isset($validRegions[$data['region_code']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'reason' => "The selected region code '{$data['region_code']}' is invalid.",
                    'errors' => ["The selected region code '{$data['region_code']}' is invalid."],
                ];
                continue;
            }

            if (! empty($data['branch_code']) && ! isset($validBranches[$data['branch_code']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'reason' => "The selected branch code '{$data['branch_code']}' is invalid.",
                    'errors' => ["The selected branch code '{$data['branch_code']}' is invalid."],
                ];
                continue;
            }

            $validator = Validator::make($data, [
                'employee_code' => ['required', 'string', 'max:50'],
                'f_name' => ['required', 'string', 'max:100'],
                'l_name' => ['required', 'string', 'max:100'],
                'full_name' => ['required', 'string', 'max:255'],
                'name_with_initials' => ['required', 'string', 'max:150'],
                'employee_type' => ['required', Rule::in(['permanent', 'contract', 'probation', 'intern', 'part_time'])],
                'id_type' => ['required', Rule::in(['nic', 'passport', 'driving_license'])],
                'id_number' => ['required', 'string', 'max:50'],
                'date_of_birth' => ['required', 'date'],
                'email' => ['required', 'email', 'max:255'],
                'phone' => ['required', 'string', 'max:30'],
                'address_line_1' => ['required', 'string', 'max:255'],
                'city' => ['required', 'string', 'max:100'],
                'country' => ['required', 'string', 'max:100'],
                'phone_primary' => ['required', 'string', 'max:30'],
                'province_code' => ['required', 'string', 'max:20'],
                'zonal_code' => ['required', 'string', 'max:20'],
                'region_code' => ['required', 'string', 'max:20'],
                'department_code' => ['required', 'string', 'max:20'],
                'designation_code' => ['required', 'string', 'max:20'],
                'start_date' => ['required', 'date'],
            ]);

            if ($validator->fails()) {
                $failed++;
                $errList = $validator->errors()->all();
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'reason' => implode('; ', $errList),
                    'errors' => $errList,
                ];
                continue;
            }

            try {
                DB::transaction(function () use ($data) {
                    Employee::create([
                        'employee_code' => $data['employee_code'],
                        'f_name' => $data['f_name'],
                        'l_name' => $data['l_name'],
                        'full_name' => $data['full_name'],
                        'name_with_initials' => $data['name_with_initials'],
                        'employee_type' => $data['employee_type'],
                        'id_type' => $data['id_type'],
                        'id_number' => $data['id_number'],
                        'date_of_birth' => $data['date_of_birth'],
                        'email' => $data['email'],
                        'phone' => $data['phone'],
                        'address_line_1' => $data['address_line_1'],
                        'city' => $data['city'],
                        'country' => $data['country'],
                        'phone_primary' => $data['phone_primary'],
                        'province_code' => $data['province_code'],
                        'zonal_code' => $data['zonal_code'],
                        'region_code' => $data['region_code'],
                        'department_code' => $data['department_code'],
                        'designation_code' => $data['designation_code'],
                        'start_date' => $data['start_date'],
                        'is_active' => true,
                    ]);
                });

                $seenEmployeeCodes[$data['employee_code']] = true;
                $existingEmployeeCodes[$data['employee_code']] = true;
                if (! empty($data['id_number'])) {
                    $seenIdNumbers[$data['id_number']] = true;
                    $existingIdNumbers[$data['id_number']] = true;
                }

                $successful++;
            } catch (\Exception $e) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'reason' => $e->getMessage(),
                    'errors' => [$e->getMessage()],
                ];
            }
        }

        fclose($handle);

        $this->auditLogger->log(
            action: 'EMPLOYEES_BULK_IMPORTED',
            entityType: 'Employee',
            entityId: 'BATCH',
            beforeData: null,
            afterData: [
                'successful' => $successful,
                'failed' => $failed,
                'total_rows' => $rowIndex - 1,
            ],
            status: 'SUCCESS',
            actorUserId: $actor->id
        );

        return [
            'status' => 'success',
            'summary' => [
                'total' => $successful + $failed,
                'successful' => $successful,
                'failed' => $failed,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * Process bulk User / Principal import.
     * Generates server-side temporary password and sets must_change_password = true.
     *
     * @param UploadedFile $file
     * @param User $actor
     * @return array<string, mixed>
     * @throws HttpException
     */
    public function importUsers(UploadedFile $file, User $actor): array
    {
        $this->validateFile($file);

        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw new HttpException(422, 'Unable to open uploaded CSV file.');
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);
            throw new HttpException(422, 'Uploaded CSV file is empty.');
        }

        $header[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header[0]);
        $header = array_map('trim', $header);

        $expected = $this->getUserTemplateHeaders();

        // Detect wrong template: Employee Master CSV uploaded to User import
        if (in_array('id_type', $header) || in_array('id_number', $header) || in_array('f_name', $header) || in_array('start_date', $header)) {
            fclose($handle);
            throw new HttpException(422, 'This appears to be an Employee Master import file. Please use Onboard Employee → Bulk Import Employees.');
        }

        $missing = array_diff($expected, $header);
        if (! empty($missing)) {
            fclose($handle);
            throw new HttpException(422, 'Missing required CSV columns: ' . implode(', ', $missing));
        }

        $rowIndex = 1;
        $successful = 0;
        $failed = 0;
        $errors = [];

        $seenUsernames = [];
        $seenEmployeeCodes = [];
        $seenEmails = [];

        // Pre-load existing records into memory to avoid N*5 queries per CSV row
        $existingEmployeeCodes = array_flip(Employee::pluck('employee_code')->all());
        $existingUserEmployeeCodes = array_flip(User::whereNotNull('employee_code')->pluck('employee_code')->all());
        $existingUsernames = array_flip(User::pluck('username')->all());
        $existingEmails = array_flip(User::pluck('email')->all());
        $rolesByName = Role::where('guard_name', 'web')->with('permissions')->get()->keyBy('name');

        while (($row = fgetcsv($handle)) !== false) {
            $rowIndex++;
            if (empty(array_filter($row))) {
                continue;
            }

            if (count($row) !== count($header)) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => null,
                    'username' => null,
                    'reason' => 'Row column count does not match CSV header count.',
                    'errors' => ['Row column count does not match CSV header count.'],
                ];
                continue;
            }

            $data = array_combine($header, array_map('trim', $row));

            // In-batch duplicate checks
            if (isset($seenUsernames[$data['username']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'username' => $data['username'] ?? null,
                    'reason' => "Duplicate username '{$data['username']}' in CSV.",
                    'errors' => ["Duplicate username '{$data['username']}' in CSV."],
                ];
                continue;
            }
            if (isset($seenEmployeeCodes[$data['employee_code']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'username' => $data['username'] ?? null,
                    'reason' => "Duplicate employee_code '{$data['employee_code']}' in CSV.",
                    'errors' => ["Duplicate employee_code '{$data['employee_code']}' in CSV."],
                ];
                continue;
            }
            if (isset($seenEmails[$data['email']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'username' => $data['username'] ?? null,
                    'reason' => "Duplicate email '{$data['email']}' in CSV.",
                    'errors' => ["Duplicate email '{$data['email']}' in CSV."],
                ];
                continue;
            }

            $seenUsernames[$data['username']] = true;
            $seenEmployeeCodes[$data['employee_code']] = true;
            $seenEmails[$data['email']] = true;

            // In-memory database uniqueness and existence checks
            if (! isset($existingEmployeeCodes[$data['employee_code']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'username' => $data['username'] ?? null,
                    'reason' => 'The selected employee code is invalid.',
                    'errors' => ['The selected employee code is invalid.'],
                ];
                continue;
            }
            if (isset($existingUserEmployeeCodes[$data['employee_code']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'username' => $data['username'] ?? null,
                    'reason' => 'The employee code has already been taken.',
                    'errors' => ['The employee code has already been taken.'],
                ];
                continue;
            }
            if (isset($existingUsernames[$data['username']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'username' => $data['username'] ?? null,
                    'reason' => 'The username has already been taken.',
                    'errors' => ['The username has already been taken.'],
                ];
                continue;
            }
            if (isset($existingEmails[$data['email']])) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'username' => $data['username'] ?? null,
                    'reason' => 'The email has already been taken.',
                    'errors' => ['The email has already been taken.'],
                ];
                continue;
            }

            $roleName = ! empty($data['role']) ? $data['role'] : 'Staff';
            if (! $rolesByName->has($roleName)) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'username' => $data['username'] ?? null,
                    'reason' => 'Selected role must be an existing GIAM internal role.',
                    'errors' => ['Selected role must be an existing GIAM internal role.'],
                ];
                continue;
            }

            $validator = Validator::make($data, [
                'employee_code' => ['required', 'string'],
                'username' => ['required', 'string', 'max:100'],
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'user_type' => ['nullable', Rule::in(['staff', 'admin', 'system'])],
                'is_active' => ['nullable'],
                'can_login' => ['nullable'],
                'role' => ['nullable', 'string'],
            ]);

            if ($validator->fails()) {
                $failed++;
                $errList = $validator->errors()->all();
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'username' => $data['username'] ?? null,
                    'reason' => implode('; ', $errList),
                    'errors' => $errList,
                ];
                continue;
            }

            try {
                // Privilege escalation validation for role assignment
                $targetRole = $rolesByName->get($roleName);
                $this->grantAuthorityService->validateRoleAssignment($actor, collect([$targetRole]));

                $rawPassword = Str::random(16) . '@A1';

                $createdUser = DB::transaction(function () use ($data, $rawPassword, $roleName, $actor) {
                    $user = User::create([
                        'employee_code' => $data['employee_code'],
                        'name' => $data['name'],
                        'username' => $data['username'],
                        'email' => $data['email'],
                        'password' => Hash::make($rawPassword),
                        'user_type' => $data['user_type'] ?: 'staff',
                        'is_active' => isset($data['is_active']) ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN) : true,
                        'can_login' => isset($data['can_login']) ? filter_var($data['can_login'], FILTER_VALIDATE_BOOLEAN) : true,
                        'must_change_password' => true,
                        'password_changed_at' => now(),
                    ]);

                    $user->syncRoles([$roleName]);

                    $this->auditLogger->log(
                        action: 'USER_CREATED',
                        entityType: 'User',
                        entityId: (string) $user->id,
                        beforeData: null,
                        afterData: $user->toArray(),
                        status: 'SUCCESS',
                        actorUserId: $actor->id
                    );

                    return $user;
                });

                // Trigger safe email if configured (no plaintext password logged)
                $this->userCreationService->sendNewAccountEmailSafeExternal($createdUser, $rawPassword);

                $seenUsernames[$data['username']] = true;
                $existingUsernames[$data['username']] = true;
                $seenEmployeeCodes[$data['employee_code']] = true;
                $existingUserEmployeeCodes[$data['employee_code']] = true;
                $seenEmails[$data['email']] = true;
                $existingEmails[$data['email']] = true;

                $successful++;
            } catch (\Exception $e) {
                $failed++;
                $errors[] = [
                    'row' => $rowIndex,
                    'employee_code' => $data['employee_code'] ?? null,
                    'username' => $data['username'] ?? null,
                    'reason' => $e->getMessage(),
                    'errors' => [$e->getMessage()],
                ];
            }
        }

        fclose($handle);

        $this->auditLogger->log(
            action: 'USERS_BULK_IMPORTED',
            entityType: 'User',
            entityId: 'BATCH',
            beforeData: null,
            afterData: [
                'successful' => $successful,
                'failed' => $failed,
                'total_rows' => $rowIndex - 1,
            ],
            status: 'SUCCESS',
            actorUserId: $actor->id
        );

        return [
            'status' => 'success',
            'summary' => [
                'total' => $successful + $failed,
                'successful' => $successful,
                'failed' => $failed,
            ],
            'errors' => $errors,
        ];
    }

    protected function validateFile(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new HttpException(422, 'Uploaded file is corrupted or failed to upload.');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension !== 'csv') {
            throw new HttpException(422, 'Only .csv files are supported.');
        }

        // Limit file size to 5MB
        if ($file->getSize() > 5 * 1024 * 1024) {
            throw new HttpException(422, 'CSV file exceeds maximum allowed size of 5MB.');
        }
    }
}
