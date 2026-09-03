<?php

namespace App\Http\Requests\Draft;

use Illuminate\Foundation\Http\FormRequest;

class SaveDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'draft_token' => ['nullable', 'string', 'max:64'],
            'current_step' => ['required', 'integer', 'between:1,3'],
            'form_data' => ['required', 'array'],
        ];
    }
}
