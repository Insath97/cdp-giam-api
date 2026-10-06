<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
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
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('role') && ! $this->has('roles')) {
            $this->merge(['roles' => [$this->input('role')]]);
        }
        if ($this->has('giam_role') && ! $this->has('roles')) {
            $this->merge(['roles' => [$this->input('giam_role')]]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('id') ?? $this->route('user');

        return [
            'version' => ['required', 'integer'], // Optimistic locking
            'username' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('users')->ignore($userId)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'password' => ['nullable', 'string', 'min:8'],
            'user_type' => ['sometimes', Rule::in(['staff', 'admin', 'system'])],
            'can_login' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web')],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $userId = $this->route('id') ?? $this->route('user');
            $user = \App\Models\User::where('id', $userId)
                ->orWhere('username', $userId)
                ->first();

            if ($user && $this->has('employee_code') && $this->input('employee_code') !== $user->employee_code) {
                $validator->errors()->add('employee_code', 'The employee code is immutable and cannot be changed.');
            }
        });
    }
}
