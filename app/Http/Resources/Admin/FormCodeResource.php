<?php

namespace App\Http\Resources\Admin;

use App\Models\FormCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FormCode
 */
class FormCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'document_code' => $this->document_code,
            'effectivity_date' => $this->effectivity_date,
            'status' => (bool) $this->status,
            'updated_at' => $this->updated_at,
        ];
    }
}
