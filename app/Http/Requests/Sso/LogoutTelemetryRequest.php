<?php

namespace App\Http\Requests\Sso;

use Illuminate\Foundation\Http\FormRequest;

class LogoutTelemetryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'string'],
            'client_secret' => ['required', 'string'],
            'external_ref' => ['required', 'string'],
            'session_id' => ['nullable', 'string'],
        ];
    }
}
