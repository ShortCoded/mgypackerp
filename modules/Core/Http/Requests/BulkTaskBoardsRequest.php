<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkTaskBoardsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $routeName = (string) $this->route()?->getName();

        $permission = match (true) {
            str_ends_with($routeName, 'bulk-delete') => 'task_boards.delete',
            str_ends_with($routeName, 'bulk-activate') => 'task_boards.bulk_activate',
            str_ends_with($routeName, 'bulk-deactivate') => 'task_boards.bulk_deactivate',
            str_ends_with($routeName, 'bulk-restore') => 'task_boards.restore',
            default => 'task_boards.view',
        };

        return (bool) $this->user()?->can($permission);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'doc_nums' => ['required', 'array', 'min:1', 'max:500'],
            'doc_nums.*' => ['required', 'string', 'max:50', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $docNums = $this->input('doc_nums', []);

        if (! is_array($docNums)) {
            $docNums = [];
        }

        $this->merge([
            'doc_nums' => collect($docNums)
                ->map(fn (mixed $docNum): string => trim((string) $docNum))
                ->filter(fn (string $docNum): bool => $docNum !== '')
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'doc_nums' => __('task_boards.selected'),
            'doc_nums.*' => __('task_boards.attributes.doc_num'),
        ];
    }
}
