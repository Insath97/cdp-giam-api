<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $roleId = $this->route('role');
        $roleObj = \App\Models\Role::find($roleId);

        if ($roleObj && $roleObj->application_id !== null) {
            return false; // Cannot update project-owned roles
        }

        if ($this->input('application_id') !== null) {
            return false; // Cannot change to project-owned
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $roleId = $this->route('role');
        $roleObj = \App\Models\Role::find($roleId);
        $appId = $this->application_id ?? $roleObj?->application_id;

        return [
            'application_id' => 'sometimes|nullable|integer|exists:applications,id',
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('roles')->where(function ($query) use ($appId) {
                    return $query->where('application_id', $appId);
                })->ignore($roleId)
            ],
            'permissions' => 'sometimes|array',
            'permissions.*' => 'exists:permissions,id'
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errorMessages = $validator->errors();

        $fieldErrors = collect($errorMessages->getMessages())->map(function ($messages, $field) {
            return [
                'field' => $field,
                'messages' => $messages,
            ];
        })->values();

        $message = $fieldErrors->count() > 1
            ? 'There are multiple validation errors. Please review the form and correct the issues.'
            : 'There is an issue with the input for ' . $fieldErrors->first()['field'] . '.';

        throw new HttpResponseException(response()->json([
            'message' => $message,
            'errors' => $fieldErrors,
        ], 422));
    }
}
