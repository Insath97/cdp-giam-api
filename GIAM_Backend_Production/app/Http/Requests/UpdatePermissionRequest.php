<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdatePermissionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $permissionId = $this->route('permission');
        $permissionObj = \App\Models\Permission::find($permissionId);

        if ($permissionObj && ($permissionObj->application_id !== null || $permissionObj->module_id !== null)) {
            return false; // Cannot update project-owned permissions
        }

        if ($this->input('application_id') !== null || $this->input('module_id') !== null) {
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
        $permissionId = $this->route('permission');
        $permissionObj = \App\Models\Permission::find($permissionId);

        $moduleId = $this->has('module_id') ? $this->module_id : $permissionObj?->module_id;
        $appId = $this->has('application_id') ? $this->application_id : $permissionObj?->application_id;

        if ($this->has('module_id') && $moduleId) {
            $module = \App\Models\Module::find($moduleId);
            if ($module) {
                $appId = $module->application_id;
            }
        }

        return [
            'group_name' => 'sometimes|required|string|max:255',
            'module_id' => 'sometimes|nullable|integer|exists:modules,id',
            'application_id' => 'sometimes|nullable|integer|exists:applications,id',
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('permissions')->where(function ($query) use ($appId) {
                    return $query->where('application_id', $appId);
                })->ignore($permissionId)
            ],
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
