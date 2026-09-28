<?php

namespace App\Http\Requests\Applications;

use App\Enums\ApplicationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'required', Rule::enum(ApplicationStatus::class)],
            'notes' => ['nullable', 'string', 'max:10000'],
            'applied_date' => ['nullable', 'date'],
        ];
    }
}
