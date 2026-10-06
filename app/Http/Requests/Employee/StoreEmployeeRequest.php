<?php

namespace App\Http\Requests\Employee;

use App\Services\User\SriLankaPhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $createUser = $this->boolean('create_user_account') || $this->boolean('create_user');
        if ($createUser) {
            return $this->user() && $this->user()->can('USER_CREATE');
        }

        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => SriLankaPhoneNormalizer::normalize($this->phone),
            'phone_primary' => SriLankaPhoneNormalizer::normalize($this->phone_primary),
            'phone_secondary' => SriLankaPhoneNormalizer::normalize($this->phone_secondary),
            'whatsapp_number' => SriLankaPhoneNormalizer::normalize($this->whatsapp_number),
            'country' => $this->country ?? 'Sri Lanka',
            'is_active' => $this->boolean('is_active', true),
            'have_whatsapp' => $this->boolean('have_whatsapp', false),
            'create_user_account' => $this->has('create_user_account') 
                ? $this->boolean('create_user_account') 
                : ($this->has('create_user') ? $this->boolean('create_user') : false),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $idNumberRules = ['required', 'string', 'max:50'];
        if ($this->input('id_type', 'nic') === 'nic') {
            $idNumberRules[] = 'regex:/^([0-9]{9}[vVxX]|[0-9]{12})$/';
        }

        return [
            'employee_code' => ['required', 'string', 'max:50', 'unique:employees,employee_code'],
            'f_name' => ['required', 'string', 'max:100'],
            'l_name' => ['required', 'string', 'max:100'],
            'full_name' => ['required', 'string', 'max:255'],
            'name_with_initials' => ['required', 'string', 'max:150'],
            'employee_type' => ['required', Rule::in(['permanent', 'contract', 'probation', 'intern', 'part_time'])],
            'id_type' => ['required', Rule::in(['nic', 'passport', 'driving_license'])],
            'id_number' => array_merge($idNumberRules, [
                Rule::unique('employees')->where(fn ($query) => $query->where('id_type', $this->input('id_type'))),
            ]),
            'date_of_birth' => ['required', 'date'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone_primary' => ['required', 'string', 'max:30'],
            'phone_secondary' => ['nullable', 'string', 'max:30'],
            'have_whatsapp' => ['boolean'],
            'whatsapp_number' => ['nullable', 'string', 'max:30'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['boolean'],

            // Org reference validation
            'province_code' => ['required', 'string', 'exists:org_provinces,code'],
            'zonal_code' => ['required', 'string', 'exists:org_zones,code'],
            'region_code' => ['required', 'string', 'exists:org_regions,code'],
            'branch_code' => ['nullable', 'string', 'exists:org_branches,code'],
            'department_code' => ['required', 'string', 'exists:org_departments,code'],
            'designation_code' => ['required', 'string', 'exists:org_designations,code'],
            'reporting_manager_code' => ['nullable', 'string', 'exists:employees,employee_code'],

            // HR Project Request Information
            'requested_project_name' => ['nullable', 'string', 'max:255'],
            'nature_of_role' => ['nullable', 'string', 'max:2000'],

            // Optional linked User provisioning
            'create_user_account' => ['boolean'],
            'username' => ['nullable', 'string', 'max:100', 'unique:users,username'],
            'password' => ['nullable', 'string', 'min:8'],
            'user_type' => ['nullable', Rule::in(['staff', 'admin', 'system'])],
            'can_login' => ['nullable', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],

            // Multi-Project Access Assignment
            'project_access' => ['nullable', 'array'],
            'project_access.*.roles' => ['nullable', 'array'],
            'project_access.*.roles.*' => ['integer'],
            'project_access.*.permissions' => ['nullable', 'array'],
            'project_access.*.permissions.*' => ['integer'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            // Require explicit GIAM role when provisioning user account
            if (! empty($this->input('create_user_account'))) {
                $roles = $this->input('roles');
                if (empty($roles) || ! is_array($roles) || count($roles) === 0) {
                    $validator->errors()->add('roles', 'A valid GIAM internal role must be explicitly selected when creating a user account.');
                }
            }

            $projectAccess = $this->input('project_access');
            if (empty($projectAccess) || ! is_array($projectAccess)) {
                return;
            }

            foreach ($projectAccess as $projectId => $assignments) {
                $project = \App\Models\Project::find($projectId);
                if (! $project) {
                    $validator->errors()->add("project_access.{$projectId}", "Invalid project ID [{$projectId}]: project does not exist.");
                    continue;
                }

                $roleIds = $assignments['roles'] ?? [];
                if (! empty($roleIds) && is_array($roleIds)) {
                    $validRoleCount = \App\Models\ProjectRole::where('project_id', $project->id)
                        ->whereIn('id', $roleIds)
                        ->count();

                    if ($validRoleCount !== count($roleIds)) {
                        $validator->errors()->add(
                            "project_access.{$projectId}.roles",
                            "One or more roles do not belong to project [{$project->name}]."
                        );
                    }
                }

                $permIds = $assignments['permissions'] ?? [];
                if (! empty($permIds) && is_array($permIds)) {
                    $validPermCount = \App\Models\ProjectPermission::where('project_id', $project->id)
                        ->whereIn('id', $permIds)
                        ->count();

                    if ($validPermCount !== count($permIds)) {
                        $validator->errors()->add(
                            "project_access.{$projectId}.permissions",
                            "One or more permissions do not belong to project [{$project->name}]."
                        );
                    }
                }
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'id_number.regex' => 'The NIC number format is invalid. It must be 9 digits followed by V/X, or 12 digits.',
            'id_number.unique' => 'An employee with this identification number and document type already exists.',
            'employee_code.unique' => 'The employee code has already been taken.',
        ];
    }
}
