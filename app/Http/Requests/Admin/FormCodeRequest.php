<?php

namespace App\Http\Requests\Admin;

/**
 * Validation for editing a form code: the document code (up to 60 characters) and the effectivity
 * date as free text (up to 40 characters, for example "(TBA)" or "December 1, 2026"). The key, the
 * name and the status are not editable, so any other field in the request is ignored.
 */
class FormCodeRequest extends AdminResourceRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'document_code' => ['required', 'string', 'max:60'],
            'effectivity_date' => ['required', 'string', 'max:40'],
        ];
    }
}
