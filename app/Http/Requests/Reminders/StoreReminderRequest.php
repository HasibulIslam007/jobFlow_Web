<?php

namespace App\Http\Requests\Reminders;

use Illuminate\Foundation\Http\FormRequest;

class StoreReminderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Ownership is validated through the parent job policy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * notification_days = how many days before the deadline to notify.
     * The UI offers 1/3/5/7; validation stays a range so the API is not
     * needlessly brittle.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'notification_days' => ['required', 'integer', 'min:1', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'notification_days.required' => 'Choose how many days before the deadline to be reminded.',
            'notification_days.integer' => 'Reminder days must be a whole number.',
            'notification_days.min' => 'The reminder must be at least 1 day before the deadline.',
            'notification_days.max' => 'The reminder may not be more than 30 days before the deadline.',
        ];
    }
}
