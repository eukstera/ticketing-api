<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

class ConsolidatedReportRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('consolidated-report');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'date_field' => ['nullable', 'in:requested,completed'],
            'requesting_area' => ['nullable', 'array', 'max:200'],
            'requesting_area.*' => ['string', 'max:255'],
            'status_id' => ['nullable', 'array', 'max:50'],
            'status_id.*' => ['integer'],
            'priority_id' => ['nullable', 'array', 'max:50'],
            'priority_id.*' => ['integer'],
            'concern_type_id' => ['nullable', 'array', 'max:200'],
            'concern_type_id.*' => ['integer'],
            'staff_id' => ['nullable', 'array', 'max:500'],
            'staff_id.*' => ['integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * The filters the report runs on: the last 30 days by request date unless told otherwise.
     *
     * @return array{from: Carbon, to: Carbon, date_field: string, requesting_area: list<string>, status_id: list<int>, priority_id: list<int>, concern_type_id: list<int>, staff_id: list<int>}
     */
    public function filters(): array
    {
        $to = $this->filled('to') ? Carbon::parse($this->input('to')) : Carbon::today();
        $from = $this->filled('from') ? Carbon::parse($this->input('from')) : $to->copy()->subDays(29);
        $ids = fn (string $key): array => array_values(array_map('intval', (array) $this->input($key, [])));

        return [
            'from' => $from->startOfDay(),
            'to' => $to->endOfDay(),
            'date_field' => $this->input('date_field', 'requested'),
            'requesting_area' => array_values(array_map('strval', (array) $this->input('requesting_area', []))),
            'status_id' => $ids('status_id'),
            'priority_id' => $ids('priority_id'),
            'concern_type_id' => $ids('concern_type_id'),
            'staff_id' => $ids('staff_id'),
        ];
    }
}
