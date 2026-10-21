<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'status' => (bool) $this->status,
            'department_id' => (int) $this->department_id,
            'designation_id' => (int) $this->designation_id,
            'role' => [
                'id' => (int) $this->role_id,
                'name' => $this->role?->role,
            ],
            'department' => [
                'id' => (int) $this->department_id,
                'name' => $this->department?->department,
            ],
            'designation' => [
                'id' => (int) $this->designation_id,
                'name' => $this->designation?->designation,
            ],
        ];
    }
}
