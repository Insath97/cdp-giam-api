<?php

namespace App\Http\Requests\Sso;

use Illuminate\Foundation\Http\FormRequest;

class AuthorizeSsoRequest extends FormRequest
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
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'redirect_uri' => ['required', 'string', 'url'],
            'code_challenge' => ['sometimes', 'nullable', 'string', 'min:43', 'max:128'],
            'code_challenge_method' => ['sometimes', 'nullable', 'string', 'in:S256'],
            'state' => ['nullable', 'string', 'max:500'],
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
            'code_challenge_method.in' => 'Only the S256 code challenge method is permitted.',
        ];
    }
}
