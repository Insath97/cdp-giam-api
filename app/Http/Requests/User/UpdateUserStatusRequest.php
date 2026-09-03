<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'can_login' => ['nullable', 'boolean'],
        ];
    }
}
