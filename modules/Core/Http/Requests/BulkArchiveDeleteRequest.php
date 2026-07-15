<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkArchiveDeleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('file_manager.delete');
    }

    protected function prepareForValidation(): void
    {
        $fileDocNums = $this->input('file_doc_nums', []);

        if (is_scalar($fileDocNums)) {
            $fileDocNums = [$fileDocNums];
        }

        if (! is_array($fileDocNums)) {
            $fileDocNums = [];
        }

        $normalizedDocNums = [];

        foreach ($fileDocNums as $fileDocNum) {
            if (! is_scalar($fileDocNum)) {
                continue;
            }

            $fileDocNum = trim((string) $fileDocNum);

            if ($fileDocNum !== '') {
                $normalizedDocNums[] = $fileDocNum;
            }
        }

        $this->merge([
            'file_doc_nums' => $normalizedDocNums,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file_doc_nums' => ['required', 'array', 'min:1'],
            'file_doc_nums.*' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file_doc_nums.required' => __('archive.no_files_selected'),
            'file_doc_nums.array' => __('archive.no_files_selected'),
            'file_doc_nums.min' => __('archive.no_files_selected'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'file_doc_nums' => __('archive.selected_files'),
            'file_doc_nums.*' => __('archive.file'),
        ];
    }
}
