<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;

class RevokeProjectAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
            'version' => ['nullable', 'integer'],
        ];
    }
}
