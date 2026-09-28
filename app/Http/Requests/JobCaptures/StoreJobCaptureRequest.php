<?php

namespace App\Http\Requests\JobCaptures;

use App\Enums\JobCaptureType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreJobCaptureRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::enum(JobCaptureType::class)],
            'content' => [
                Rule::requiredIf(fn () => in_array($this->input('type'), [JobCaptureType::Text->value, JobCaptureType::Url->value], true)),
                'nullable',
                'string',
                'max:50000',
                Rule::when(
                    $this->input('type') === JobCaptureType::Url->value,
                    ['url', 'max:2048', 'starts_with:http://,https://'],
                ),
            ],
            'file' => [
                Rule::requiredIf(
                    fn () => in_array($this->input('type'), [JobCaptureType::Pdf->value, JobCaptureType::Image->value], true)
                        && ! $this->filled('file_path')
                ),
                'nullable',
                Rule::when(
                    $this->input('type') === JobCaptureType::Pdf->value,
                    [File::types(['pdf'])->max(10 * 1024)]
                ),
                Rule::when(
                    $this->input('type') === JobCaptureType::Image->value,
                    [File::types(['jpg', 'jpeg', 'png', 'webp'])->max(10 * 1024)]
                ),
            ],
            'file_path' => ['nullable', 'string', 'max:1024'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'The capture type is required.',
            'type.Illuminate\Validation\Rules\Enum' => 'Invalid capture type provided. Supported types are: text, pdf, image, url.',
            'content.required' => 'Content is required when capture type is text or url.',
            'content.required_if' => 'Content is required when capture type is text or url.',
            'content.url' => 'Content must be a valid URL starting with http:// or https://.',
            'content.starts_with' => 'Only http and https URLs are supported.',
            'file.required' => 'A file is required for document or image captures.',
            'file.required_if' => 'A file is required for document or image captures.',
            'file.mimes' => 'The uploaded file format is not supported.',
            'file.types' => 'The uploaded file format is not supported.',
            'file.max' => 'The file size may not exceed 10MB.',
        ];
    }
}
