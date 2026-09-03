<?php

namespace App\Services\Import;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLoggerService;
use App\Services\User\UserCreationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BulkImportService
{
    public function __construct(
        protected AuditLoggerService $auditLogger,
        protected UserCreationService $userCreationService
    ) {}

    /**
     * Plain column headers for Employee CSV Template.
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
     * Plain column headers for User / Principal CSV Template.
     * Note: NO plaintext password column. Server automatically generates secure temporary passwords.
     */
    public function getUserTemplateHeaders(): array
    {
        return [
            'employee_code',
            'username',
            'name',
            'email',
            'user_type',
            'is_active',
            'can_login',
            'role',
        ];
    }

    /**
     * Process bulk employee import.
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

        // Track codes within the batch to detect duplicates in the file
        $seenEmployeeCodes = [];
        $seenIdNumbers = [];

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

            // In-batch duplicate checks
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
            }

            $validator = Validator::make($data, [
                'employee_code' => ['required', 'string', 'max:50', 'unique:employees,employee_code'],
                'f_name' => ['required', 'string', 'max:100'],
                'l_name' => ['required', 'string', 'max:100'],
                'full_name' => ['required', 'string', 'max:255'],
                'name_with_initials' => ['required', 'string', 'max:150'],
                'employee_type' => ['required', Rule::in(['permanent', 'contract', 'probation', 'intern', 'part_time'])],
                'id_type' => ['required', Rule::in(['nic', 'passport', 'driving_license'])],
                'id_number' => ['required', 'string', 'max:50', 'unique:employees,id_number'],
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

            $validator = Validator::make($data, [
                'employee_code' => ['required', 'string', 'exists:employees,employee_code', 'unique:users,employee_code'],
                'username' => ['required', 'string', 'max:100', 'unique:users,username'],
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'user_type' => ['nullable', Rule::in(['staff', 'admin', 'system'])],
                'is_active' => ['nullable'],
                'can_login' => ['nullable'],
                'role' => ['nullable', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
            ], [
                'role.exists' => 'Selected role must be an existing GIAM internal role.',
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
                $roleName = ! empty($data['role']) ? $data['role'] : 'Staff';
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
