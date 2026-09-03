<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:project_roles,id'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:project_permissions,id'],
            'version' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'role_ids.*.exists' => 'One or more selected roles do not exist in the catalog.',
            'permission_ids.*.exists' => 'One or more selected permissions do not exist in the catalog.',
        ];
    }
}
