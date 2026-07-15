<?php

namespace Modules\Core\Http\Requests\Calendar;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Models\CalendarEvent;
use Modules\Core\Services\DateFormatService;

class UpdateCalendarEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('calendar.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'starts_at' => ['required', 'string'],
            'ends_at' => ['nullable', 'string'],
            'all_day' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', Rule::in(CalendarEvent::Statuses)],
            'color' => ['nullable', 'string', Rule::in(CalendarEvent::Colors)],
            'location' => ['nullable', 'string', 'max:255'],
            'meeting_url' => ['nullable', 'url', 'max:2048'],
            'reminder_at' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $dates = app(DateFormatService::class);
            $startsAt = $dates->parseDateTime(is_string($this->input('starts_at')) ? $this->input('starts_at') : null);
            $endsAt = $dates->parseDateTime(is_string($this->input('ends_at')) ? $this->input('ends_at') : null);
            $reminderAt = $dates->parseDateTime(is_string($this->input('reminder_at')) ? $this->input('reminder_at') : null);

            if (! $startsAt) {
                $validator->errors()->add('starts_at', __('calendar.validation.datetime'));
            }

            if ($this->filled('ends_at') && ! $endsAt) {
                $validator->errors()->add('ends_at', __('calendar.validation.datetime'));
            }

            if ($startsAt && $endsAt && $endsAt->lt($startsAt)) {
                $validator->errors()->add('ends_at', __('calendar.validation.ends_at_after_or_equal'));
            }

            if ($this->filled('reminder_at') && ! $reminderAt) {
                $validator->errors()->add('reminder_at', __('calendar.validation.datetime'));
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'all_day' => $this->boolean('all_day'),
        ]);
    }

    /**
     * @param  string|null  $key
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        $dates = app(DateFormatService::class);

        foreach (['starts_at', 'ends_at', 'reminder_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $dates->normalizeDateTimeForStorage(is_string($data[$field]) ? $data[$field] : null);
            }
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('calendar.attributes');
    }
}
