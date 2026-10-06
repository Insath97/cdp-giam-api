<?php

namespace App\Http\Requests\Project;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectIntegrationRequest extends FormRequest
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
        $projectParam = $this->route('id') ?? $this->route('project');
        $project = $projectParam
            ? Project::with('integration')->where('id', $projectParam)->orWhere('code', $projectParam)->first()
            : null;
        $integrationId = $project?->integration?->id;

        $clientIdRule = Rule::unique('project_integrations', 'client_id');
        if ($integrationId) {
            $clientIdRule->ignore($integrationId);
        }

        return [
            'api_base_url' => ['sometimes', 'required', 'url', 'max:255'],
            'auth_method' => ['sometimes', Rule::in(['bearer_token', 'oauth2', 'hmac', 'api_key'])],
            'client_id' => ['nullable', 'string', 'max:100', $clientIdRule],
            'client_secret' => ['nullable', 'string'],
            'allowed_user_fields' => ['sometimes', 'required', 'array'],
            'allowed_user_fields.*' => ['string', Rule::in(\App\Models\ProjectIntegration::VALID_PROJECTION_FIELDS)],
            'sync_enabled' => ['sometimes', 'boolean'],
            'sso_enabled' => ['sometimes', 'boolean'],
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
            'allowed_user_fields.*.in' => 'Field :input is not a permitted projection field.',
        ];
    }
}
