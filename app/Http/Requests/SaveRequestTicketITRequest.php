<?php

namespace App\Http\Requests;

use App\Models\TypeOfConcern;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRequestTicketITRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'service_department' => ['required', 'integer', Rule::exists('departments', 'id')->where('status', true)],
            'area_supervisor' => ['required', 'string'],
            'type_of_concern' => ['required', 'string', Rule::notIn(TypeOfConcern::where('status', false)->pluck('label')->all())],
            'devices' => ['nullable', 'array', 'required_if:type_of_concern,Hardware'],
            'devices.*.type' => ['required', 'string'],
            'devices.*.quantity' => ['required', 'integer', 'min:1'],
            'name_of_area' => ['required', 'string'],
            'remarks' => ['required', 'string'],
            'assigned_user' => ['required', 'integer', Rule::exists('users', 'id')->where('status', true)],
            'priority' => ['required', 'integer', Rule::exists('priority', 'id')->where('status', true)],
            'requestor' => ['required', 'string'],
        ];
    }
}
