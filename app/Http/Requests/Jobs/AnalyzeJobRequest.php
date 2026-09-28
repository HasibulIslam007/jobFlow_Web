<?php

namespace App\Http\Requests\Jobs;

use Illuminate\Foundation\Http\FormRequest;

class AnalyzeJobRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:text'],
            'content' => ['required', 'string', 'min:20', 'max:50000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'Currently only "text" job analysis is supported.',
            'content.min' => 'Job description must be at least 20 characters long.',
            'content.max' => 'Job description may not exceed 50,000 characters.',
        ];
    }
}
