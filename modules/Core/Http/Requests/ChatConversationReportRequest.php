<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChatConversationReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('chat.reports.view');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'q' => trim((string) $this->input('q')) ?: null,
            'participant' => trim((string) $this->input('participant')) ?: null,
            'status' => $this->input('status') ?: 'all',
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'participant' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'has_attachments' => ['nullable', Rule::in(['0', '1', 0, 1])],
            'status' => ['nullable', Rule::in(['all', 'active', 'deleted'])],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'q' => __('chat.report.filters.search'),
            'participant' => __('chat.report.filters.participant'),
            'date_from' => __('chat.report.filters.date_from'),
            'date_to' => __('chat.report.filters.date_to'),
            'has_attachments' => __('chat.report.filters.has_attachments'),
            'status' => __('chat.report.filters.status'),
        ];
    }
}
