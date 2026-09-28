<?php

namespace App\Http\Requests\Resumes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreResumeRequest extends FormRequest
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
     * PDF only initially — the extractor architecture (ResumeExtractor)
     * accepts DOCX/TXT in a later phase, and validation will widen with it.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'file' => ['required', File::types(['pdf'])->max(10 * 1024)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.max' => 'The title may not exceed 255 characters.',
            'file.required' => 'A resume file is required.',
            'file.types' => 'The uploaded file format is not supported. Only PDF resumes are accepted.',
            'file.mimes' => 'The uploaded file format is not supported. Only PDF resumes are accepted.',
            'file.max' => 'The file size may not exceed 10MB.',
        ];
    }
}
