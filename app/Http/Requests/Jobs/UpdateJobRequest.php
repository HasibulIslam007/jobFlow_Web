<?php

namespace App\Http\Requests\Jobs;

use App\Enums\JobStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateJobRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'company' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'salary' => ['nullable', 'string', 'max:255'],
            'deadline' => ['nullable', 'date'],
            'source_type' => ['nullable', 'string', 'max:32'],
            'source_url' => ['nullable', 'url', 'max:2048'],
            'status' => ['nullable', Rule::enum(JobStatus::class)],
        ];
    }
}
