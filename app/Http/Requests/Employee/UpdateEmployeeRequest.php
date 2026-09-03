<?php

namespace App\Http\Requests\Employee;

use App\Services\User\SriLankaPhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $mergeData = [];
        if ($this->has('phone')) {
            $mergeData['phone'] = SriLankaPhoneNormalizer::normalize($this->phone);
        }
        if ($this->has('phone_primary')) {
            $mergeData['phone_primary'] = SriLankaPhoneNormalizer::normalize($this->phone_primary);
        }
        if ($this->has('phone_secondary')) {
            $mergeData['phone_secondary'] = SriLankaPhoneNormalizer::normalize($this->phone_secondary);
        }
        if ($this->has('whatsapp_number')) {
            $mergeData['whatsapp_number'] = SriLankaPhoneNormalizer::normalize($this->whatsapp_number);
        }
        if (! empty($mergeData)) {
            $this->merge($mergeData);
        }
    }

    public function rules(): array
    {
        $employeeId = $this->route('id') ?? $this->route('employee');

        $idNumberRules = ['sometimes', 'required', 'string', 'max:50'];
        $idType = $this->input('id_type', $this->employee?->id_type ?? 'nic');
        if ($idType === 'nic') {
            $idNumberRules[] = 'regex:/^([0-9]{9}[vVxX]|[0-9]{12})$/';
        }

        return [
            'version' => ['required', 'integer'], // Optimistic lock version
            'f_name' => ['sometimes', 'required', 'string', 'max:100'],
            'l_name' => ['sometimes', 'required', 'string', 'max:100'],
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'name_with_initials' => ['sometimes', 'required', 'string', 'max:150'],
            'employee_type' => ['sometimes', 'required', Rule::in(['permanent', 'contract', 'probation', 'intern', 'part_time'])],
            'id_type' => ['sometimes', 'required', Rule::in(['nic', 'passport', 'driving_license'])],
            'id_number' => array_merge($idNumberRules, [
                Rule::unique('employees')->where(fn ($query) => $query->where('id_type', $idType))->ignore($employeeId),
            ]),
            'date_of_birth' => ['sometimes', 'required', 'date'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:30'],
            'address_line_1' => ['sometimes', 'required', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['sometimes', 'required', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'phone_primary' => ['sometimes', 'required', 'string', 'max:30'],
            'phone_secondary' => ['nullable', 'string', 'max:30'],
            'have_whatsapp' => ['boolean'],
            'whatsapp_number' => ['nullable', 'string', 'max:30'],
            'start_date' => ['sometimes', 'required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['boolean'],

            'province_code' => ['sometimes', 'required', 'string', 'exists:org_provinces,code'],
            'zonal_code' => ['sometimes', 'required', 'string', 'exists:org_zones,code'],
            'region_code' => ['sometimes', 'required', 'string', 'exists:org_regions,code'],
            'branch_code' => ['nullable', 'string', 'exists:org_branches,code'],
            'department_code' => ['sometimes', 'required', 'string', 'exists:org_departments,code'],
            'designation_code' => ['sometimes', 'required', 'string', 'exists:org_designations,code'],
            'reporting_manager_code' => ['nullable', 'string', 'exists:employees,employee_code'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $employeeId = $this->route('id') ?? $this->route('employee');
            $employee = \App\Models\Employee::where('id', $employeeId)
                ->orWhere('employee_code', $employeeId)
                ->first();

            if (! $employee) {
                return;
            }

            // Employee code must be immutable
            if ($this->has('employee_code') && $this->input('employee_code') !== $employee->employee_code) {
                $validator->errors()->add('employee_code', 'The employee code is immutable and cannot be changed.');
            }

            // If official email is changing, verify users.email uniqueness for linked user
            if ($this->has('email') && $this->input('email') !== $employee->email) {
                $newEmail = $this->input('email');
                $linkedUser = $employee->user;
                if ($linkedUser) {
                    $userExists = \App\Models\User::where('email', $newEmail)
                        ->where('id', '!=', $linkedUser->id)
                        ->exists();

                    if ($userExists) {
                        $validator->errors()->add('email', 'The official email is already taken by another GIAM user account.');
                    }
                } else {
                    $userExists = \App\Models\User::where('email', $newEmail)->exists();
                    if ($userExists) {
                        $validator->errors()->add('email', 'The official email is already taken by a GIAM user account.');
                    }
                }
            }
        });
    }
}
