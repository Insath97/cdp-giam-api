<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'api_base_url' => ['sometimes', 'required', 'url', 'max:255'],
            'auth_method' => ['sometimes', Rule::in(['bearer_token', 'oauth2', 'hmac', 'api_key'])],
            'client_id' => ['nullable', 'string', 'max:100'],
            'client_secret' => ['nullable', 'string'],
            'allowed_user_fields' => ['sometimes', 'required', 'array'],
            'allowed_user_fields.*' => ['string', Rule::in(StoreProjectRequest::VALID_PROJECTION_FIELDS)],
            'sync_enabled' => ['sometimes', 'boolean'],
            'sso_enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'allowed_user_fields.*.in' => 'Field :input is not a permitted projection field.',
        ];
    }
}
