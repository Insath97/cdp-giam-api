<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;

class AssignProjectAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'exists:projects,id'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:project_roles,id'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:project_permissions,id'],
            'version' => ['nullable', 'integer'], // Required for update, null for new assignment
        ];
    }

    public function messages(): array
    {
        return [
            'project_id.required' => 'A valid project must be specified for access assignment.',
            'role_ids.*.exists' => 'One or more selected roles do not exist in the catalog.',
            'permission_ids.*.exists' => 'One or more selected permissions do not exist in the catalog.',
        ];
    }
}
