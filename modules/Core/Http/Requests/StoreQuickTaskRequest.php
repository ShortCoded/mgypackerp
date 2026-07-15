<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\ValidatesQuickTaskPayload;

class StoreQuickTaskRequest extends FormRequest
{
    use ValidatesQuickTaskPayload;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('quick_tasks.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->quickTaskRules();
    }

    protected function prepareForValidation(): void
    {
        $this->prepareQuickTaskForValidation();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateQuickTaskAttachmentSelections($validator);
        });
    }
}
