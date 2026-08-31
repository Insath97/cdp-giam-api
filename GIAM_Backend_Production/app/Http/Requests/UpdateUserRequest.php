<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $id = $this->route('user');
        $userObj = \App\Models\User::find($id);
        $employeeId = $userObj?->employee_id ?? 'NULL';

        return [
            'name' => 'sometimes|string|max:255',
            'username' => 'sometimes|string|max:255|unique:users,username,' . $id,
            'email' => 'nullable|email|max:255|unique:users,email,' . $id,
            'password' => 'sometimes|string|min:8',
            'user_type' => 'sometimes|in:admin,staff',
            'role' => 'sometimes|string|exists:roles,name',

            // Application-wise roles/permissions validation
            'applications' => 'sometimes|array',
            'applications.*.application_id' => [
                'required',
                'exists:applications,id',
                function ($attribute, $value, $fail) {
                    $user = \Illuminate\Support\Facades\Auth::user();
                    if (!$user->hasRole('Super Admin') && !$user->can('Project Access Assign')) {
                        // Check if the current admin is authorized for this application
                        $hasAccess = $user->applications()->where('applications.id', $value)->exists();
                        if (!$hasAccess) {
                            $fail('You are not authorized to assign access for this application.');
                        }
                    }
                }
            ],
            'applications.*.roles' => 'sometimes|array',
            'applications.*.roles.*' => [
                'integer',
                'exists:roles,id',
            ],
            'applications.*.permission_groups' => 'sometimes|array',
            'applications.*.permission_groups.*' => ['integer','exists:permission_groups,id'],
            'applications.*.module_ids' => 'sometimes|array',
            'applications.*.module_ids.*' => ['integer','exists:modules,id'],
            'applications.*.permissions' => 'sometimes|array',
            'applications.*.permissions.*' => [
                'integer',
                'exists:permissions,id',
            ],

            // Staff specific validation (embedded employee details)
            'employee_type' => 'sometimes|in:permanent,contract,internship,probation',
            'date_of_birth' => 'sometimes|date',
            'employee_code' => 'sometimes|string|unique:employees,employee_code,' . $employeeId,
            'id_type' => 'sometimes|in:nic,passport,driving_license,other',
            'id_number' => 'sometimes|string|unique:employees,id_number,' . $employeeId,
            'phone' => 'nullable|string',
            'phone_primary' => 'sometimes|string',
            'phone_secondary' => 'nullable|string',
            'address_line_1' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'country' => 'nullable|string',
            'postal_code' => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
            'zonal_id' => 'nullable|exists:zonals,id',
            'region_id' => 'nullable|exists:regions,id',
            'province_id' => 'nullable|exists:provinces,id',
            'designation_id' => 'nullable|exists:designations,id',
            'reporting_manager_id' => 'nullable|exists:employees,id',

            'is_active' => 'sometimes|boolean',
            'can_login' => 'sometimes|boolean',
        ];
    }

    public function bodyParameters()
    {
        return [];
    }

    protected function failedValidation(Validator $validator)
    {
        $errorMessages = $validator->errors();
        $fieldErrors = collect($errorMessages->getMessages())->map(function ($messages, $field) {
            return [
                'field' => $field,
                'messages' => $messages,
            ];
        })->values();
        $message = $fieldErrors->count() > 1
            ? 'There are multiple validation errors. Please review the form and correct the issues.'
            : 'There is an issue with the input for ' . $fieldErrors->first()['field'] . '.';
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => $fieldErrors,
        ], 422));
    }

    public function withValidator(Validator $validator)
    {
        $validator->after(function ($validator) {
            $applications = $this->input('applications', []);
            foreach ($applications as $index => $appInput) {
                $appId = $appInput['application_id'] ?? null;
                if (!$appId) continue;

                if (!empty($appInput['module_ids'])) {
                    $invalidModules = \App\Models\Module::whereIn('id',$appInput['module_ids'])->where('application_id','!=',$appId)->pluck('id')->toArray();
                    if (!empty($invalidModules)) $validator->errors()->add("applications.{$index}.module_ids", 'One or more modules do not belong to the specified application.');
                }

                if (!empty($appInput['roles'])) {
                    $invalidRoles = \App\Models\Role::whereIn('id', $appInput['roles'])
                        ->where(function($q) use ($appId) {
                            $q->where('application_id', '!=', $appId)
                              ->orWhereNull('application_id');
                        })->pluck('id')->toArray();
                    
                    if (!empty($invalidRoles)) {
                        $validator->errors()->add("applications.{$index}.roles", 'One or more roles do not belong to the specified application.');
                    }
                }

                if (!empty($appInput['permission_groups'])) {
                    $invalidGroups = \App\Models\PermissionGroup::whereIn('id', $appInput['permission_groups'])
                        ->where(function($q) use ($appId) { $q->where('application_id', '!=', $appId)->orWhereNull('application_id'); })->pluck('id')->toArray();
                    if (!empty($invalidGroups)) $validator->errors()->add("applications.{$index}.permission_groups", 'One or more permission groups do not belong to the specified application.');
                }

                if (!empty($appInput['permissions'])) {
                    $invalidPerms = \App\Models\Permission::whereIn('id', $appInput['permissions'])
                        ->where(function($q) use ($appId) {
                            $q->where('application_id', '!=', $appId)
                              ->orWhereNull('application_id');
                        })->pluck('id')->toArray();
                    
                    if (!empty($invalidPerms)) {
                        $validator->errors()->add("applications.{$index}.permissions", 'One or more permissions do not belong to the specified application.');
                    }
                }
            }
        });
    }
}
