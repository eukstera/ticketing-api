<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssignTicketRequest extends FormRequest
{
    /**
     * Department Heads assign anyone; other staff may only take an untaken ticket for themselves.
     */
    public function authorize(): Response
    {
        $ticket = $this->route('ticket');
        $isSelfTake = $ticket->assigned_user === null && (int) $this->input('user_id') === (int) $this->user()->id;

        return Gate::inspect($isSelfTake ? 'take' : 'assign', $ticket);
    }

    /**
     * The assignee must belong to the handling department of the ticket.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn (Builder $query) => $query->where('department_id', $this->route('ticket')->department)
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.exists' => 'The selected user does not belong to the department handling this ticket.',
        ];
    }
}
