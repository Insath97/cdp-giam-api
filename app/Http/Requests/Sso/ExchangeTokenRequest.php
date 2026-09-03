<?php

namespace App\Http\Requests\Sso;

use Illuminate\Foundation\Http\FormRequest;

class ExchangeTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
            'code_verifier' => ['nullable', 'string'],
            'client_id' => ['required', 'string'],
            'client_secret' => ['required', 'string'],
        ];
    }
}
