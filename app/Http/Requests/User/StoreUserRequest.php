<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('role') && ! $this->has('roles')) {
            $this->merge(['roles' => [$this->input('role')]]);
        }
        if ($this->has('giam_role') && ! $this->has('roles')) {
            $this->merge(['roles' => [$this->input('giam_role')]]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_code' => ['required', 'string', 'exists:employees,employee_code', 'unique:users,employee_code'],
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'user_type' => ['sometimes', Rule::in(['staff', 'admin', 'system'])],
            'can_login' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ];
    }
    
    public function messages(): array
    {
        return [
            'roles.required' => 'A valid GIAM internal role must be explicitly selected.',
            'roles.min' => 'A valid GIAM internal role must be explicitly selected.',
            'roles.*.exists' => 'The selected GIAM internal role is invalid or does not exist.',
        ];
    }
}
