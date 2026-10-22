<?php

namespace App\Http\Requests;

use App\Models\TicketSurvey;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The seven blocks of the Customer Satisfaction Survey, 5 = Strongly Agree to 1 = Strongly Disagree.
 */
class ConfirmationSurveyRequest extends FormRequest
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
        $rules = ['ratings' => ['required', 'array'], 'comments' => ['nullable', 'string', 'max:1000']];

        foreach (TicketSurvey::BLOCKS as $block) {
            $rules["ratings.{$block}"] = ['required', 'integer', 'between:1,5'];
        }

        return $rules;
    }
}
