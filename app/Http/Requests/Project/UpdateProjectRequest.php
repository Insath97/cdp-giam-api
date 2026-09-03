<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $projectId = $this->route('id') ?? $this->route('project');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'base_url' => ['sometimes', 'required', 'url', 'max:255'],
            'icon_url' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'maintenance', 'disabled'])],
        ];
    }
}
