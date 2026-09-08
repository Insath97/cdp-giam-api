<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public const VALID_PROJECTION_FIELDS = [
        'employee_code',
        'f_name',
        'l_name',
        'full_name',
        'name_with_initials',
        'id_type',
        'id_number',
        'date_of_birth',
        'email',
        'phone',
        'phone_primary',
        'phone_secondary',
        'address_line_1',
        'city',
        'state',
        'country',
        'postal_code',
        'department_code',
        'designation_code',
        'reporting_manager_code',
        'start_date',
        'end_date',
    ];

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
        return [
            'code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:projects,code'],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'base_url' => ['required', 'url', 'max:255'],
            'icon_url' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'maintenance', 'disabled'])],

            // Integration details (optional on initial registration)
            'integration' => ['nullable', 'array'],
            'integration.api_base_url' => ['required_with:integration', 'url', 'max:255'],
            'integration.auth_method' => ['sometimes', Rule::in(['bearer_token', 'oauth2', 'hmac', 'api_key'])],
            'integration.client_id' => ['nullable', 'string', 'max:100', 'unique:project_integrations,client_id'],
            'integration.client_secret' => ['nullable', 'string'],
            'integration.allowed_user_fields' => ['required_with:integration', 'array'],
            'integration.allowed_user_fields.*' => ['string', Rule::in(self::VALID_PROJECTION_FIELDS)],
            'integration.sync_enabled' => ['sometimes', 'boolean'],
            'integration.sso_enabled' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'integration.allowed_user_fields.*.in' => 'Field :input is not a permitted projection field.',
        ];
    }
}
