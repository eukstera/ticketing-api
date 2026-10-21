<?php

namespace App\Http\Requests;

use App\Models\Designation;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAdminUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('admin');
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['sometimes', 'string', 'min:8'],
            'department_id' => ['sometimes', 'integer', 'exists:departments,id'],
            'designation_id' => ['sometimes', 'integer', 'exists:designations,id'],
            'role_id' => ['sometimes', 'integer', 'exists:roles,id'],
            'status' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The designation must belong to the department the user ends up in,
     * whether either value is sent in this request or kept from the user.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (! $this->hasAny(['department_id', 'designation_id'])) {
                    return;
                }

                /** @var User $user */
                $user = $this->route('user');
                $departmentId = (int) $this->input('department_id', $user->department_id);
                $designationId = (int) $this->input('designation_id', $user->designation_id);

                $belongs = Designation::where('id', $designationId)
                    ->where('department_id', $departmentId)
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add('designation_id', 'The selected designation does not belong to the selected department.');
                }
            },
        ];
    }
}
