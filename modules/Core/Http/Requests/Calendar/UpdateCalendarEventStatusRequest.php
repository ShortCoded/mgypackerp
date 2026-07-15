<?php

namespace Modules\Core\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Models\CalendarEvent;

class UpdateCalendarEventStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('calendar.complete');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(CalendarEvent::Statuses)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('calendar.attributes');
    }
}
