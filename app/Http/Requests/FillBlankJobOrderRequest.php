<?php

namespace App\Http\Requests;

use App\Models\TypeOfConcern;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FillBlankJobOrderRequest extends FormRequest
{
    /**
     * Only the member who printed the form (its owner) and Department Heads fill it
     * in. Handing it to someone else follows the assign rule: Department Heads only.
     */
    public function authorize(): Response
    {
        $ticket = $this->route('ticket');
        $response = Gate::inspect('fillBlank', $ticket);

        if ($response->denied()) {
            return $response;
        }

        $assignee = $this->input('assigned_user');

        if ($assignee !== null && $assignee !== '' && (int) $assignee !== (int) $ticket->assigned_user) {
            return Gate::inspect('assign', $ticket);
        }

        return $response;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $department = (int) $this->route('ticket')->department;

        return [
            'requested_at' => ['required', 'date', 'before_or_equal:now'],
            'requestor' => ['required', 'string', 'max:255'],
            'requestor_email' => ['nullable', 'email', 'max:255'],
            'area_supervisor' => ['required', 'string'],
            'type_of_concern' => ['required', 'string', Rule::notIn(TypeOfConcern::where('status', false)->pluck('label')->all())],
            'devices' => ['nullable', 'array', 'required_if:type_of_concern,Hardware'],
            'devices.*.type' => ['required', 'string'],
            'devices.*.quantity' => ['required', 'integer', 'min:1'],
            'name_of_area' => ['required', 'string'],
            'remarks' => ['required', 'string'],
            'priority' => ['required', 'integer', Rule::exists('priority', 'id')->where('status', true)->where('department_id', $department)],
            'assigned_user' => ['nullable', 'integer', Rule::exists('users', 'id')->where('status', true)->where('department_id', $department)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('requestor_email');

        if (is_string($email)) {
            $email = mb_strtolower(trim($email));
            $this->merge(['requestor_email' => $email === '' ? null : $email]);
        }
    }
}
