<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkArchiveDownloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('file_manager.download');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file_doc_nums' => ['required', 'array', 'min:1', 'max:'.((int) config('archive.bulk_download.max_files', 100))],
            'file_doc_nums.*' => ['required', 'string', 'distinct'],
        ];
    }
}
